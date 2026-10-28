<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Log\AsyncJsonLogger;
use Semitexa\Core\Log\UdpLogShipper;

/**
 * LOG_REMOTE: the logger had no remote sink of its own; a syslog route was
 * dropped because its socket held the worker. A datagram never waits.
 */
final class UdpLogShipperTest extends TestCase
{
    /** @var resource */
    private $server;
    private string $address;
    private string $stderr;

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['LOG_REMOTE', 'LOG_SINK', 'LOG_LEVEL', 'LOG_CHANNELS'] as $key) {
            $this->saved[$key] = getenv($key);
        }
        $server = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($server, (string) $error);
        $this->server = $server;
        $this->address = 'udp://' . stream_socket_get_name($server, false);
        $this->stderr = sys_get_temp_dir() . '/udp-log-shipper-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }
        fclose($this->server);
        @unlink($this->stderr);
    }

    #[Test]
    public function every_written_line_also_goes_out_as_one_datagram(): void
    {
        $logger = $this->logger();

        $logger->warning('disk almost full', ['_channel' => 'storage', 'free' => '2%']);
        $logger->error('payment declined', ['order' => 7]);
        $logger->warning('not routed', ['_channel' => 'noisy']);
        $logger->flush();

        $first = json_decode($this->receive(), true);
        $second = json_decode($this->receive(), true);
        self::assertSame(['disk almost full', 'storage'], [$first['message'] ?? null, $first['channel'] ?? null]);
        self::assertSame('payment declined', $second['message'] ?? null);
        self::assertSame('', $this->receive(), 'a channel routed nowhere is not sent either');
    }

    #[Test]
    public function an_entry_too_large_for_a_datagram_goes_as_a_stub_that_says_so(): void
    {
        $logger = $this->logger();

        $logger->error('huge', ['dump' => str_repeat('x', UdpLogShipper::MAX_DATAGRAM + 10)]);
        $logger->flush();

        $stub = json_decode($this->receive(), true);
        self::assertSame('huge', $stub['message'] ?? null);
        self::assertStringContainsString('over what one datagram carries', (string) ($stub['truncated'] ?? ''));
    }

    #[Test]
    public function only_a_udp_address_is_taken(): void
    {
        // A host name would be looked up on the first send; the coroutine
        // runtime opens udp:// as IPv4 whatever the address.
        foreach (['', 'tcp://10.0.0.1:514', 'udp://host', 'udp://collector.internal:5140', 'udp://[::1]:5140', 'udp://10.0.0.1:99999', 'udp://999.0.0.1:514', '10.0.0.1:514'] as $value) {
            $rejected = [];
            self::assertNull(UdpLogShipper::fromEnv($value, $rejected), $value);
        }
        self::assertInstanceOf(UdpLogShipper::class, UdpLogShipper::fromEnv('udp://10.0.0.5:5140'));
    }

    private function logger(): AsyncJsonLogger
    {
        putenv('LOG_REMOTE=' . $this->address);
        putenv('LOG_SINK=stderr');
        putenv('LOG_LEVEL=debug');
        putenv('LOG_CHANNELS=noisy=off');
        $logger = new AsyncJsonLogger();
        (new \ReflectionProperty($logger, 'environment'))->setValue($logger, (new \ReflectionClass(Environment::class))->newInstanceWithoutConstructor());
        (new \ReflectionProperty($logger, 'stderrTarget'))->setValue($logger, $this->stderr);

        return $logger;
    }

    /**
     * Four lines to a closed port and the next one through said `remote_dropped: 1`:
     * a datagram the kernel accepts can still be lost, so only a number on every
     * line lets the collector see the loss. It does not depend on the port
     * answering "unreachable", which not every kernel delivers.
     */
    #[Test]
    public function every_line_is_numbered_so_the_collector_sees_a_gap(): void
    {
        $closed = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($closed);
        $address = 'udp://' . stream_socket_get_name($closed, false);
        fclose($closed);
        $shipper = UdpLogShipper::fromEnv($address);
        self::assertNotNull($shipper);
        for ($i = 0; $i < 4; $i++) {
            $shipper->ship('{"message":"lost ' . $i . '"}', []);
        }

        // The collector comes back on that port.
        $back = stream_socket_server($address, $errno, $error, STREAM_SERVER_BIND);
        self::assertIsResource($back, (string) $error);
        fclose($this->server);
        $this->server = $back;
        $shipper->ship('{"message":"through"}', []);

        $line = json_decode($this->receive(), true);
        self::assertSame('through', $line['message'] ?? null);
        self::assertSame(5, $line['remote_seq'] ?? null, 'the first line it sees is the fifth: four are missing');
    }

    /** Lines skipped while the socket could not be opened were lost with no count at all. */
    #[Test]
    public function lines_not_sent_while_backing_off_are_counted_into_the_next(): void
    {
        $shipper = UdpLogShipper::fromEnv($this->address);
        self::assertNotNull($shipper);
        $failedAt = new \ReflectionProperty(UdpLogShipper::class, 'failedAt');
        $failedAt->setValue($shipper, microtime(true));
        for ($i = 0; $i < 3; $i++) {
            $shipper->ship('{"message":"while away ' . $i . '"}', []);
        }
        self::assertSame('', $this->receive(), 'nothing is sent while backing off');

        $failedAt->setValue($shipper, 0.0);
        $shipper->ship('{"message":"back"}', []);
        $shipper->ship('{"message":"after"}', []);

        self::assertSame(['message' => 'back', 'remote_seq' => 4, 'remote_dropped' => 3], json_decode($this->receive(), true));
        self::assertSame(['message' => 'after', 'remote_seq' => 5], json_decode($this->receive(), true), 'said once');
    }

    private function receive(): string
    {
        $read = [$this->server];
        $none = null;
        if (stream_select($read, $none, $none, 0, 300_000) !== 1) {
            return '';
        }

        return (string) stream_socket_recvfrom($this->server, 65535);
    }

    /** The context went to the collector as written, passwords included, over plain UDP. */
    #[Test]
    public function secrets_in_the_context_do_not_leave_the_machine(): void
    {
        $entry = ['message' => 'login', 'context' => [
            'password' => 'hunter2',
            'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'text/html'],
            'api_key' => 'sk-123',
            'prompt_tokens' => 812,
            'user' => 'ann',
            'request_headers' => ['authorization' => ['Bearer abc']],
            'cookies' => ['sid' => 's3cr3t'],
            'pin_password' => 123456,
            'csrf_token' => 'x',
            'dsn' => 'mysql://u:pw@h/db',
            'passenger' => 'Ann Lee',
        ]];

        $redacted = UdpLogShipper::redacted($entry);

        self::assertNotNull($redacted);
        self::assertSame('[redacted]', $redacted['context']['password']);
        self::assertSame('[redacted]', $redacted['context']['headers']['Authorization']);
        self::assertSame('text/html', $redacted['context']['headers']['Accept']);
        self::assertSame('[redacted]', $redacted['context']['api_key']);
        self::assertSame(812, $redacted['context']['prompt_tokens'], 'a count is not a secret');
        self::assertSame('ann', $redacted['context']['user']);
        self::assertSame('[redacted]', $redacted['context']['request_headers']['authorization'], 'a header map holds a list');
        self::assertSame('[redacted]', $redacted['context']['cookies'], 'a cookie map');
        self::assertSame('[redacted]', $redacted['context']['pin_password'], 'a number under a secret key');
        self::assertSame('[redacted]', $redacted['context']['csrf_token']);
        self::assertSame('[redacted]', $redacted['context']['dsn']);
        self::assertSame('Ann Lee', $redacted['context']['passenger'], 'not every word starting with pass');
        self::assertNull(UdpLogShipper::redacted(['message' => 'ok', 'context' => ['user' => 'ann']]), 'nothing to redact, nothing re-encoded');
    }

    /** Only keys were read: a secret inside a string, or a DTO's property, went out over plain UDP. */
    #[Test]
    public function secrets_inside_text_and_objects_do_not_leave_the_machine(): void
    {
        $dto = new class {
            public string $password = 'hunter2';
            public string $email = 'ann@example.com';
        };
        $entry = ['message' => 'Redis auth failed: redis://:s3cret@10.0.0.4:6379', 'context' => [
            'error' => 'mysql://app:pw@db/app unreachable',
            'curl' => "> GET / HTTP/1.1\nAuthorization: Basic YW5uOmh1bnRlcjI=\nCookie: sid=1; theme=dark\nAccept: */*",
            'cli' => 'curl -H "Bearer abc123.def"',
            'url' => '/dl?file=a.pdf&signature=f00d&token=beef',
            'body' => '{"user":"ann","password":"two words"}',
            'dto' => $dto,
            'site' => 'https://example.com:8080/a?b=c@d',
            'note' => 'prompt_tokens=812, passenger=Ann',
        ]];

        $redacted = UdpLogShipper::redacted($entry);

        self::assertNotNull($redacted);
        self::assertSame('Redis auth failed: redis://[redacted]@10.0.0.4:6379', $redacted['message']);
        self::assertSame('mysql://[redacted]@db/app unreachable', $redacted['context']['error']);
        self::assertSame("> GET / HTTP/1.1\nAuthorization: [redacted]\nCookie: [redacted]\nAccept: */*", $redacted['context']['curl']);
        self::assertSame('curl -H "Bearer [redacted]"', $redacted['context']['cli']);
        self::assertSame('/dl?file=a.pdf&signature=[redacted]&token=[redacted]', $redacted['context']['url']);
        self::assertSame('{"user":"ann","password":[redacted]}', $redacted['context']['body']);
        self::assertSame(['password' => '[redacted]', 'email' => 'ann@example.com'], $redacted['context']['dto'], 'read as it would be encoded');
        self::assertSame('https://example.com:8080/a?b=c@d', $redacted['context']['site'], 'a port is not a password');
        self::assertSame('prompt_tokens=812, passenger=Ann', $redacted['context']['note']);
        self::assertNull(UdpLogShipper::redacted(['message' => 'ok', 'context' => ['at' => '2026-10-28T10:00:00+00:00', 'e' => new \RuntimeException('x')]]), 'an object with nothing to redact stays as it was');
    }

    /** A line over the datagram size went as a stub built from the message as written. */
    #[Test]
    public function the_stub_for_a_large_entry_carries_the_redacted_message(): void
    {
        $logger = $this->logger();

        $logger->error('auth failed for redis://:s3cret@10.0.0.4', ['dump' => str_repeat('x', UdpLogShipper::MAX_DATAGRAM + 10)]);
        $logger->flush();

        self::assertSame('auth failed for redis://[redacted]@10.0.0.4', json_decode($this->receive(), true)['message'] ?? null);
    }
}
