<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Recycles a worker after SWOOLE_MAX_REQUEST requests without cutting its clients.
 *
 * Swoole's own max_request gives them no warning: the worker stops, its keep-alive
 * connections are closed, and a request already on its way down one is lost.
 * Measured at 10000 requests under load: 0.13% of requests, none with recycling
 * off. So the worker counts for itself, says `Connection: close` as it nears the
 * end, and leaves a moment later. A client with a request in that moment hears it
 * and goes; an idle one hears nothing and is closed by the drain, as it always was.
 * A connection told to go is closed by the server too, a second later: the header
 * alone leaves a client that reads to the end of the stream waiting. Not at once:
 * closing as the response ends races the client and turned clean exits into resets
 * (0 lost became 120 in 10 s on a 20 ms handler), and a client that listens has
 * left by then.
 *
 * That alone costs more than it saves. The clients all move to the other workers
 * in one go and a keep-alive connection never moves again, so the replacement
 * starts with nothing and stays idle: 3800 req/s fell to 2900 for good. A
 * connection therefore ends after a tenth of the worker's limit, as nginx's
 * keepalive_requests does, and the load keeps finding every worker. On the
 * framework, limit 10000, two interleaved rounds on a fast JSON route: 310 of 270k
 * requests lost became 19 of 296k, with no cost that could be measured.
 * A request longer than the 100 ms a leaving worker waits heard nothing when it
 * arrived, so slow routes gain less: on a 200 ms handler the loss fell to a quarter.
 *
 * One worker is left to Swoole. Its stop() takes the whole server down, there is
 * no manager to start another (see WorkerCountDoctorCheck).
 *
 * Built before start(), so every worker forks its own, empty copy.
 */
final class WorkerRecycle
{
    /** How long a leaving worker gives its clients to go, in milliseconds. */
    public const LEAVE_AFTER_MS = 100;

    /** How long a client told to go has before the server closes on it, in milliseconds. */
    public const CLOSE_AFTER_MS = 1000;

    private const CONNECTION_SHARE = 10;

    private ?int $limit = null;
    private int $served = 0;
    private bool $leaving = false;

    /** @var array<int, int> requests served per open connection */
    private array $onConnection = [];

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $workers,
    ) {
    }

    /** Whether this class recycles the worker; otherwise Swoole's max_request does. */
    public function isActive(): bool
    {
        return self::manages($this->maxRequests, $this->workers);
    }

    public static function manages(int $maxRequests, int $workers): bool
    {
        return $maxRequests > 0 && $workers > 1;
    }

    /** A request arrived on this connection: true when its response must end it. */
    public function arrived(int $sessionId): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        // Up to half as much again, as Swoole's own max_request_grace: workers
        // that started together must not all leave together.
        $this->limit ??= $this->maxRequests + random_int(0, intdiv($this->maxRequests, 2));
        $this->served++;
        $onConnection = ($this->onConnection[$sessionId] ?? 0) + 1;
        $this->onConnection[$sessionId] = $onConnection;

        return $this->served >= $this->limit
            || $onConnection >= max(1, intdiv($this->maxRequests, self::CONNECTION_SHARE));
    }

    /** True once, when the worker has served its share and should leave. */
    public function dueToLeave(): bool
    {
        if ($this->leaving || $this->limit === null || $this->served < $this->limit) {
            return false;
        }
        $this->leaving = true;

        return true;
    }

    public function closed(int $sessionId): void
    {
        unset($this->onConnection[$sessionId]);
    }
}
