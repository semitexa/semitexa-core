<?php

declare(strict_types=1);

namespace Semitexa\Core\Redis;

use Predis\Connection\ConnectionException;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Per-worker fail-fast guard in front of every Redis call a pool makes.
 *
 * Without it, every request that touched Redis while Redis was down paid a full
 * connect attempt: with the container gone, getaddrinfo alone took 5 s, so every
 * page of the site answered 500 after five seconds (measured 2026-10-15). After
 * a connection-level failure ({@see ConnectionException}: refused, DNS, a read
 * that timed out) the breaker opens for `cooldownSeconds`; calls in that window
 * fail immediately with a {@see RedisUnavailableException} carrying the original
 * failure. When the window ends exactly one caller is let through as a probe;
 * the others keep failing fast until it resolves. A success closes the breaker.
 *
 * Errors Redis ANSWERS (a wrong type, a script error) are not outages and never
 * open it. Mirrors semitexa-orm's ConnectCircuitBreaker.
 *
 * Coroutine safety: state changes happen between yield points (the only
 * suspension is inside the Redis call itself), so two coroutines of one worker
 * cannot both claim the probe. A probe that never returns releases its claim
 * after `probeLeaseSeconds`.
 */
final class RedisCircuitBreaker
{
    private ?ConnectionException $lastFailure = null;

    private float $openedAt = 0.0;

    private float $openUntil = 0.0;

    private ?float $probeStartedAt = null;

    private int $refused = 0;

    /** The current fail-fast window: doubles on every failed probe, up to the cap. */
    private float $window = 0.0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param float $cooldownSeconds first fail-fast window after a failure; <= 0 disables the breaker
     * @param float $maxCooldownSeconds cap for the window, which doubles on every failed probe: a
     *                                  probe can itself cost a full DNS timeout, so a long outage
     *                                  must not charge one request every couple of seconds
     * @param (\Closure(): float)|null $clock monotonic seconds; injectable for tests
     */
    public function __construct(
        private readonly float $cooldownSeconds = 2.0,
        private readonly float $probeLeaseSeconds = 10.0,
        ?\Closure $clock = null,
        private readonly float $maxCooldownSeconds = 30.0,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    public function call(\Closure $call): mixed
    {
        if ($this->cooldownSeconds <= 0.0) {
            return $call();
        }

        $isProbe = false;
        $claim = null;
        $lastFailure = $this->lastFailure;
        if ($lastFailure !== null) {
            $now = ($this->clock)();
            $probeInFlight = $this->probeStartedAt !== null
                && ($now - $this->probeStartedAt) < $this->probeLeaseSeconds;

            if ($now < $this->openUntil || $probeInFlight) {
                $this->refused++;

                throw RedisUnavailableException::from($lastFailure);
            }

            $this->probeStartedAt = $now;
            $claim = $now;
            $isProbe = true;
        }

        try {
            $result = $call();
        } catch (ConnectionException $e) {
            $wasClosed = $this->lastFailure === null;
            $this->lastFailure = $e;
            $now = ($this->clock)();
            if ($wasClosed) {
                $this->window = $this->cooldownSeconds;
                $this->openUntil = $now + $this->window;
            } elseif ($isProbe) {
                $this->window = min($this->window * 2, max($this->maxCooldownSeconds, $this->cooldownSeconds));
                $this->openUntil = $now + $this->window;
            }
            // Otherwise a call that was already in flight when Redis went
            // away: it neither doubles nor extends the window — with five such
            // calls a two-second blip used to become a thirty-second outage.
            if ($isProbe && $this->probeStartedAt === $claim) {
                $this->probeStartedAt = null;
            }
            if ($wasClosed) {
                $this->openedAt = $now;
                StaticLoggerBridge::warning('redis', 'Redis unreachable; failing fast for the cooldown window.', [
                    'cooldown_seconds' => $this->cooldownSeconds,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        } catch (\Throwable $e) {
            // Redis answered (or the callback itself failed): not an outage.
            if ($isProbe && $this->probeStartedAt === $claim) {
                $this->probeStartedAt = null;
            }

            throw $e;
        }

        if ($this->lastFailure !== null) {
            StaticLoggerBridge::warning('redis', 'Redis reachable again.', [
                'outage_seconds' => round(($this->clock)() - $this->openedAt, 3),
                'refused_calls' => $this->refused,
            ]);
        }
        $this->lastFailure = null;
        $this->openUntil = 0.0;
        $this->probeStartedAt = null;
        $this->refused = 0;

        return $result;
    }

    public function isOpen(): bool
    {
        return $this->lastFailure !== null;
    }
}
