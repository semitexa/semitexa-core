<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

/**
 * LOG_REMOTE=udp://host:port: every line the logger writes is also sent there
 * as one UDP datagram of JSON, for a log collector on another machine.
 *
 * UDP because the send never waits: a datagram is handed to the kernel and
 * the worker moves on, where a syslog socket or a TCP connection to a slow
 * collector held the worker (and, during a drain, past its grace). The price
 * is UDP's: a line the network or a busy collector drops is gone, and nothing
 * says so beyond a counter in the next line that gets through. The traffic
 * is not encrypted: keep the collector on a private network. A value under a
 * key that names a secret (password, token, cookie, authorization, api key…)
 * leaves as "[redacted]", whatever it holds: the context a line carries went
 * out as written. An IPv4
 * address only: a host name would be looked up on the first send, a wait
 * outside a coroutine nothing can cut short, and the coroutine runtime opens
 * every udp:// socket as IPv4.
 */
final class UdpLogShipper
{
    /** Below the 65 507-byte UDP payload limit, with room for the stub's own fields. */
    public const MAX_DATAGRAM = 60_000;

    /** @var resource|null */
    private $socket = null;
    /** When opening the socket failed: tried again a minute later, not per line. */
    private float $failedAt = 0.0;
    private int $dropped = 0;

    /**
     * Keys that name a secret. `token` only at the end (csrf_token, api-token,
     * not prompt_tokens, a count); `pass` only whole (not passenger, passport).
     */
    private const SECRET_KEY = '/password|passwd|passphrase|^pass$|secret|token$|authorization|cookie|api[_-]?key|credential|private[_-]?key|signature|^dsn$/i';

    private function __construct(private readonly string $address) {}

    /**
     * The entry with every value under a secret-naming key replaced, at any
     * depth; null when it holds none, so the common line is not encoded twice.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    public static function redacted(array $entry): ?array
    {
        $changed = false;
        $walk = static function (array $values) use (&$walk, &$changed): array {
            foreach ($values as $key => $value) {
                // The whole value, whatever its type: a header map holds a list
                // (`authorization: ['Bearer …']`), cookies a map, a PIN an int.
                if (is_string($key) && $value !== null && $value !== '' && $value !== [] && preg_match(self::SECRET_KEY, $key) === 1) {
                    $values[$key] = '[redacted]';
                    $changed = true;
                } elseif (is_array($value)) {
                    $values[$key] = $walk($value);
                }
            }

            return $values;
        };
        $result = $walk($entry);

        return $changed ? $result : null;
    }

    /**
     * @param list<string> $rejected the value, when it is not a udp:// address
     */
    public static function fromEnv(?string $value, array &$rejected = []): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('#^udp://([0-9.]+):(\d{1,5})$#', $value, $m) !== 1
            || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || (int) $m[2] < 1 || (int) $m[2] > 65535) {
            $rejected[] = $value;

            return null;
        }

        return new self($value);
    }

    /**
     * One encoded entry (no trailing newline). One too large for a datagram
     * goes as a stub that says so, with the level, channel and the start of
     * its message.
     *
     * @param array<string, mixed> $entry
     */
    public function ship(string $line, array $entry): void
    {
        if ($this->failedAt > 0.0 && microtime(true) - $this->failedAt < 60.0) {
            return;
        }
        if (strlen($line) > self::MAX_DATAGRAM) {
            $stub = ['timestamp' => is_scalar($entry['timestamp'] ?? null) ? $entry['timestamp'] : date('c')];
            // Only plain values: the stub must encode whatever the entry held.
            foreach (['level', 'channel', 'request_id', 'cid', 'process', 'block'] as $field) {
                if (is_scalar($entry[$field] ?? null)) {
                    $stub[$field] = $entry[$field];
                }
            }
            $stub['message'] = mb_strcut(is_scalar($entry['message'] ?? null) ? (string) $entry['message'] : '', 0, 2000);
            $stub['truncated'] = sprintf('the entry was %d bytes, over what one datagram carries', strlen($line));
            $line = (string) json_encode($stub, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        if ($line === '' || !str_ends_with($line, '}')) {
            return;
        }
        if ($this->dropped > 0) {
            // Said in the next line that gets through, not in a line of its own.
            $line = substr($line, 0, -1) . ',"remote_dropped":' . $this->dropped . '}';
        }

        $socket = $this->socket();
        if ($socket === null) {
            return;
        }
        $sent = @fwrite($socket, $line);
        $this->dropped = $sent === strlen($line) ? 0 : $this->dropped + 1;
    }

    /** @return resource|null */
    private function socket()
    {
        if ($this->socket !== null) {
            return $this->socket;
        }
        $socket = @stream_socket_client($this->address, $errno, $error, 0);
        if (!is_resource($socket)) {
            // Said, then tried again a minute later: never a retry per line,
            // and a process that runs for days (a queue worker) is not left
            // without remote lines by one failure at boot.
            if ($this->failedAt === 0.0) {
                FallbackErrorLogger::log('LOG_REMOTE: ' . $this->address . ' could not be opened (' . $error . '); trying again every minute.');
            }
            $this->failedAt = microtime(true);

            return null;
        }
        $this->failedAt = 0.0;
        stream_set_blocking($socket, false);

        return $this->socket = $socket;
    }
}
