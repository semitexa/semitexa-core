<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Http\ClientIp;
use Semitexa\Core\Request;

/**
 * "At most N per window", for a route (#[RateLimit]) or from any service:
 *
 *     $decision = $this->limiter->attempt('password-reset', 5, 900);
 *     if (!$decision->allowed) { ... refuse, say retry in $decision->retryAfter s ... }
 *
 * A sliding-window counter: the window is cut into fixed slots, and a request
 * is judged by the current slot's count plus the previous slot's, weighted by
 * how much of the previous slot still lies inside the sliding window. Two
 * counters per subject and an atomic increment are all it needs, and unlike a
 * fixed window a burst at a slot's edge cannot pass twice the limit.
 * A refused request is taken back, so hammering does not extend the wait.
 */
#[AsService]
#[ExecutionScoped]
final class RateLimiter
{
    #[InjectAsReadonly]
    protected RateLimitStorage $storage;

    #[InjectAsMutable]
    protected Request $request;

    #[InjectAsMutable]
    protected AuthContextInterface $authContext;

    /**
     * @param RateLimitKey|string $by whose requests count together; a string
     *        is a subject of the caller's own (an account id, an address)
     */
    public function attempt(
        string $bucket,
        int $limit,
        int $perSeconds,
        RateLimitKey|string $by = RateLimitKey::Ip,
        ?float $now = null,
    ): RateLimitDecision {
        $limit = max(1, $limit);
        $window = max(1, $perSeconds);
        $now ??= microtime(true);

        $slot = (int) floor($now / $window);
        $elapsed = $now - $slot * $window;
        $subject = md5($bucket . '|' . $this->subject($by));
        $currentKey = "{$window}:{$slot}:{$subject}";

        $counts = $this->storage->store()->hit($currentKey, $window . ':' . ($slot - 1) . ':' . $subject, 2 * $window);
        if ($counts === null) {
            // A store that cannot count must not refuse everyone.
            return new RateLimitDecision(true, $limit, 0, 0);
        }
        [$current, $previous] = $counts;

        $weight = 1 - $elapsed / $window;
        $estimate = $previous * $weight + $current;
        if ($estimate <= $limit) {
            return new RateLimitDecision(true, $limit, (int) floor($limit - $estimate), 0);
        }

        $this->storage->store()->undo($currentKey);

        return new RateLimitDecision(false, $limit, 0, self::retryAfter($limit, $window, $elapsed, $previous, $current - 1));
    }

    /** attempt(), refusing with RateLimitException (429, Retry-After). */
    public function enforce(
        string $bucket,
        int $limit,
        int $perSeconds,
        RateLimitKey|string $by = RateLimitKey::Ip,
    ): RateLimitDecision {
        $decision = $this->attempt($bucket, $limit, $perSeconds, $by);
        if (!$decision->allowed) {
            throw new RateLimitException($decision->retryAfter);
        }

        return $decision;
    }

    /**
     * Whole seconds until one more request fits, given the counts without it:
     * later in this slot while the previous slot's weight fades, or else in
     * the next slot, where this slot's count becomes the fading one.
     */
    public static function retryAfter(int $limit, int $window, float $elapsed, int $previous, int $current): int
    {
        $wait = null;
        if ($current + 1 <= $limit && $previous > 0) {
            // previous * (1 - (elapsed + t) / window) + current + 1 <= limit
            $t = $window * (1 - ($limit - $current - 1) / $previous) - $elapsed;
            if ($t <= $window - $elapsed) {
                $wait = max(0.0, $t);
            }
        }
        if ($wait === null) {
            // current * (1 - s / window) + 1 <= limit, s into the next slot
            $s = $current > 0 ? max(0.0, $window * (1 - ($limit - 1) / $current)) : 0.0;
            $wait = $window - $elapsed + $s;
        }

        return max(1, (int) ceil($wait));
    }

    private function subject(RateLimitKey|string $by): string
    {
        if (is_string($by)) {
            return 'key:' . $by;
        }

        if ($by === RateLimitKey::User && isset($this->authContext) && !$this->authContext->isGuest()) {
            $id = $this->authContext->getUser()?->getId();
            if ($id !== null && $id !== '') {
                return 'user:' . $id;
            }
        }

        return 'ip:' . self::addressBucket(isset($this->request) ? ClientIp::of($this->request) : '');
    }

    /** An IPv6 client usually owns its whole /64: counted per address, it could rotate past any limit. */
    public static function addressBucket(string $address): string
    {
        $binary = @inet_pton($address);
        if ($binary === false || strlen($binary) !== 16) {
            return $address;
        }

        return (string) inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
