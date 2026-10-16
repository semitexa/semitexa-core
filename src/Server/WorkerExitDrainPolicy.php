<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Which coroutine the exit drain cancels, and when.
 *
 * Everything that is not serving a request is cancelled at once: recurring
 * timers' bodies, subscribers, a warm-up parked on a socket — the coroutines
 * that otherwise hold a worker hostage to "all coroutines are asleep". A
 * coroutine serving a request is spared for a grace window, so the request
 * gets its response; one still running when the window closes is a stream or
 * a stuck request, and is cancelled then. The window closes a second before
 * Swoole's max_wait_time kills the worker, so the drain, not the kill, has the
 * last word.
 *
 * MEASURED 2026-10-16 on the dev stack: cancelling on the first pass failed
 * the Redis reads of requests in flight on every recycle — each read as a
 * Redis outage (transient guest session, breaker open), and 3 of 120 requests
 * never got an answer.
 */
final readonly class WorkerExitDrainPolicy
{
    public function __construct(public float $graceSeconds)
    {
    }

    public static function forMaxWaitTime(int $maxWaitSeconds): self
    {
        return new self((float) max(0, $maxWaitSeconds - 1));
    }

    public function shouldCancel(bool $isServingRequest, float $secondsSinceDrainBegan): bool
    {
        return !$isServingRequest || $secondsSinceDrainBegan >= $this->graceSeconds;
    }
}
