<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\SwooleLogRetention;
use Semitexa\Core\Support\AppendLine;

/**
 * Logger that writes JSON lines to a file. Under the Swoole server (SAPI cli) a line is written by the
 * logging coroutine itself; see flush() for how a burst of them shares one writer.
 * Environment is injected by the container (InjectAsReadonly); config is resolved lazily on first use.
 */
#[SatisfiesServiceContract(of: LoggerInterface::class)]
final class AsyncJsonLogger implements LoggerInterface
{
    private const DEFAULT_LOG_FILE = 'var/log/app.log';

    #[InjectAsReadonly]
    protected Environment $environment;

    private ?int $minLevel = null;
    private ?string $logFile = null;
    private LogSink $sink = LogSink::File;
    /** @var array<string, LogRoute> LOG_CHANNELS: channel => where its entries go */
    private array $routes = [];
    /** Where the stderr sink writes; a test points it at a file. */
    private string $stderrTarget = 'php://stderr';
    private ?AppLogRotation $rotation = null;

    /**
     * Bytes appended since the last size check, per file. Rotation needs the file size, and a
     * stat per flush would be a syscall on the request path for a question whose answer
     * cannot change by more than what this worker just wrote. Counting locally and only
     * asking the filesystem once a slice's worth has accumulated keeps the check off the
     * hot path while still bounding the overshoot to roughly that slice per worker.
     *
     * @var array<string, int>
     */
    private array $bytesSinceSizeCheck = [];

    /**
     * Whether this worker has looked at the real file size even once.
     *
     * The counter above assumes the file was within bounds when the worker
     * started, and answers "has MY writing pushed it over". It cannot answer
     * "was it already over before I arrived", and that is the state a fresh
     * worker actually finds: nothing resets the file, so an oversized log stays
     * oversized across every restart.
     *
     * MEASURED on the dev host: `app.log` reached 380 MB — 12x the 32 MB ceiling
     * — with no rotated sibling, a week after the size policy shipped. Each
     * worker would have had to append a megabyte on its own before it so much as
     * stat'ed the file, and on a quiet host, restarted often, none ever did. The
     * policy was correct and simply never ran.
     *
     * So the first flush of every worker checks unconditionally; the counter
     * takes over from there and keeps the stat off the hot path.
     *
     * ⚠️ Rotation renames the log aside and the NEXT writer recreates it, owning
     * it. Where one uid writes the log and another runs the tests — the dev
     * workspace does exactly this, app as root and phpunit as 1000:1000 — the
     * loser is locked out and its logging silently stops. That is an ownership
     * problem, not a logging one, and is fixed the way the release stack already
     * fixes it at startup: chown var/log to the host user, chmod u+rwX,g+rwX.
     * Doing it from here would mean a logger that chowns files, which is worse.
     */
    /** @var array<string, true> */
    private array $sizeCheckedOnce = [];

    /** How much this worker may append before it re-reads the real file size. */
    private const SIZE_CHECK_EVERY_BYTES = 1_048_576;
    /** @var list<array<string, mixed>> */
    private array $buffer = [];
    private bool $deferScheduled = false;
    /** A coroutine of this process is writing; see flush(). */
    private bool $writing = false;

    private function ensureConfig(): void
    {
        if ($this->minLevel !== null) {
            return;
        }
        $levelName = Environment::getEnvValue('LOG_LEVEL');
        if ($levelName === null || $levelName === '' || !LogLevel::isValid($levelName)) {
            $levelName = $this->environment->isDev() ? 'info' : 'warning';
        }
        $this->minLevel = LogLevel::toValue($levelName);
        $logFile = Environment::getEnvValue('LOG_FILE');
        $this->logFile = $logFile !== null && $logFile !== '' ? $logFile : self::DEFAULT_LOG_FILE;
        $this->sink = LogSink::fromEnv(Environment::getEnvValue('LOG_SINK'));
        $rejected = [];
        $this->routes = LogChannelRoutes::fromEnv(Environment::getEnvValue('LOG_CHANNELS'), $this->logFile, $rejected);
        if ($rejected !== []) {
            // Said once per worker: a typo (`sql=of`) otherwise sent the
            // channel to LOG_SINK without a word.
            FallbackErrorLogger::log('LOG_CHANNELS: ignored ' . implode(', ', $rejected) . ' (a route is file, stderr, both, off or a var/log/*.log file of its own); those channels follow LOG_SINK.');
        }
        $this->rotation = new AppLogRotation(
            AppLogRotation::maxBytesFromEnv(Environment::getEnvValue('LOG_MAX_BYTES')),
            SwooleLogRetention::daysFromEnv(Environment::getEnvValue('LOG_RETENTION_DAYS')),
        );
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        $this->ensureConfig();
        if (LogLevel::toValue($level) < $this->minLevel) {
            return;
        }
        $entry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        // Extract reserved context keys into top-level entry fields
        if (isset($context['_channel'])) {
            $entry['channel'] = $context['_channel'];
            unset($entry['context']['_channel']);
        }
        if (isset($context['_request_id'])) {
            $entry['request_id'] = $context['_request_id'];
            unset($entry['context']['_request_id']);
        }

        // Add coroutine ID for request tracing in Swoole
        if (class_exists(\Swoole\Coroutine::class, false)) {
            $cid = \Swoole\Coroutine::getCid();
            if ($cid >= 0) {
                $entry['cid'] = $cid;
            }
        }

        // Which process and which part of the pipeline this line came from,
        // when anything is installed that can say. `cid` alone cannot answer
        // it: a coroutine id is RECYCLED, so it identifies a line's neighbours
        // for a moment and nothing at all an hour later. The process id is
        // durable and the block is what a reader is actually looking for.
        $origin = LogOrigin::current();
        if ($origin !== null) {
            if (isset($origin['process'])) {
                $entry['process'] = $origin['process'];
            }
            if (isset($origin['block'])) {
                $entry['block'] = $origin['block'];
            }
        }
        $this->buffer[] = $entry;

        // In CLI (e.g. queue worker) there is no Swoole event loop, so defer would never run — flush immediately.
        $useDefer = php_sapi_name() !== 'cli'
            && extension_loaded('swoole')
            && class_exists(\Swoole\Event::class)
            && method_exists(\Swoole\Event::class, 'defer');

        if ($useDefer) {
            if (!$this->deferScheduled) {
                $this->deferScheduled = true;
                \Swoole\Event::defer(function (): void {
                    $this->flush();
                    $this->deferScheduled = false;
                });
            }
        } else {
            $this->flush();
        }
    }

    /**
     * Write buffered entries to the log file (JSON lines). Call explicitly when not using defer.
     *
     * One writer per logger, and the container keeps one logger per worker: a
     * coroutine that finds a write in progress leaves its entries to that writer,
     * which takes the buffer again until it is empty. Under
     * SWOOLE_HOOK_FILE a contended LOCK_EX is very slow: MEASURED on Swoole 6.2.3,
     * 200 coroutines appending one line each took 3.4 s with every append taking
     * the lock, 0.012 s one after another. A burst like that — the drain's own
     * warnings plus the errors of the coroutines it cancelled — held leaving workers
     * past max_wait_time inside file_put_contents, where cancel() cannot reach.
     * The lock the worker PROCESSES share is tried, not waited for
     * ({@see AppendLine}): the 72h soak found a recycled worker killed with its
     * requests while it waited for it.
     */
    public function flush(): void
    {
        if ($this->writing) {
            return;
        }
        $this->writing = true;
        try {
            while ($this->buffer !== []) {
                $entries = $this->buffer;
                $this->buffer = [];
                $this->write($entries);
            }
        } finally {
            $this->writing = false;
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function write(array $entries): void
    {
        $this->ensureConfig();

        // Lines collected per destination, each in the order logged: a
        // channel LOG_CHANNELS names goes where its route says, every other
        // entry where LOG_SINK says. Grouped by route instead, a file two
        // routes write (LOG_SINK=file, security=both) got its lines reordered.
        $default = LogRoute::forSink($this->sink, $this->logFile ?? self::DEFAULT_LOG_FILE);
        $stderr = '';
        $files = [];
        foreach ($entries as $entry) {
            $channel = is_scalar($entry['channel'] ?? null) ? (string) $entry['channel'] : null;
            $route = $channel !== null ? ($this->routes[$channel] ?? $default) : $default;
            if ($route->writesNothing()) {
                continue;
            }
            $encoded = $this->encodeEntry($entry) . "\n";
            if ($route->stderr) {
                $stderr .= $encoded;
            }
            if ($route->file !== null) {
                $file = LogChannelRoutes::normalize($route->file);
                $files[$file] ??= ['line' => '', 'count' => 0];
                $files[$file]['line'] .= $encoded;
                $files[$file]['count']++;
            }
        }

        if ($stderr !== '') {
            // One write per batch. A line up to the pipe buffer (4 KiB on
            // Linux) reaches a container's log collector whole; a longer one
            // may interleave with another worker's.
            @file_put_contents($this->stderrTarget, $stderr, FILE_APPEND);
        }
        foreach ($files as $file => ['line' => $line, 'count' => $count]) {
            $this->writeFile($file, $line, $count);
        }
    }

    private function writeFile(string $file, string $line, int $entryCount): void
    {
        $root = $this->resolveProjectRoot();
        $path = $root . '/' . ltrim($file, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        error_clear_last();
        if (!AppendLine::to($path, $line)) {
            $lastError = error_get_last();
            $errorMessage = is_array($lastError)
                ? $lastError['message']
                : 'unknown error';

            error_log(sprintf(
                '[Semitexa] Logger write failed: path=%s entries=%d error=%s',
                $path,
                $entryCount,
                $errorMessage,
            ));

            return;
        }

        $this->rotateIfFull($path, strlen($line));
    }

    /**
     * Keep the application log bounded. Measured before this existed: app.log reached
     * 360 MB on the dev host while swoole.log, the file the framework does rotate, held
     * 1.2 MB — the policy was attached to the log that does not grow.
     *
     * Failure here is deliberately silent. This runs inside the logger, so a throw would
     * turn every diagnostic into an outage; an unrotated file is a disk problem, and a
     * logger that raises during teardown is a much worse one.
     */
    private function rotateIfFull(string $path, int $appended): void
    {
        $rotation = $this->rotation;
        if ($rotation === null || !$rotation->isEnabled()) {
            return;
        }

        // Counted per file: a channel's own log rotates on its own size.
        $this->bytesSinceSizeCheck[$path] = ($this->bytesSinceSizeCheck[$path] ?? 0) + $appended;
        if (isset($this->sizeCheckedOnce[$path]) && $this->bytesSinceSizeCheck[$path] < self::SIZE_CHECK_EVERY_BYTES) {
            return;
        }
        $this->sizeCheckedOnce[$path] = true;
        $this->bytesSinceSizeCheck[$path] = 0;

        clearstatcache(true, $path);
        $size = @filesize($path);
        if ($size !== false && $rotation->shouldRotate($size)) {
            $rotation->rotate($path);
        }
    }

    private function resolveProjectRoot(): string
    {
        return \Semitexa\Core\Support\ProjectRoot::get();
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function encodeEntry(array $entry): string
    {
        try {
            // Substitute invalid UTF-8 with U+FFFD: one stray byte from user
            // input must not cost the whole entry.
            return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            // Not only JsonException: a jsonSerialize() may throw anything, and the
            // writer encodes other coroutines' entries in the same batch.
            $fallbackEntry = [
                'timestamp' => date('c'),
                'level' => 'error',
                'message' => 'Log entry could not be JSON encoded',
                'context' => [
                    'original_level' => is_string($entry['level'] ?? null) ? $entry['level'] : null,
                    'original_message' => is_string($entry['message'] ?? null) ? $entry['message'] : null,
                    'encoding_error' => $exception->getMessage(),
                ],
            ];

            return json_encode($fallbackEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                ?: '{"timestamp":null,"level":"error","message":"Log entry could not be JSON encoded","context":{"encoding_error":"fallback encoding failed"}}';
        }
    }
}
