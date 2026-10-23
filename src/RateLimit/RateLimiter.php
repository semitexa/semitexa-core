<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Http\ClientIp;
use Semitexa\Core\Request;
use Semitexa\Core\Security\AppSecret;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

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

    #[InjectAsReadonly]
    protected TenantContextStoreInterface $tenants;

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
        // The limit and the kind of key are part of the counter: a guest under
        // `by: User` falls back to their address, and must not then count
        // twice into an Ip limit on the same bucket.
        // The application too: apps sharing one Redis counted a bucket
        // together, so a client's logins on one site used up its tries on the
        // other.
        $subject = md5(self::appScope() . '|' . $bucket . '|' . $limit . '|' . (is_string($by) ? 'key' : $by->value) . '|' . $this->subject($by));
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
            return new RateLimitDecision(true, $limit, (int) floor($limit - $estimate), 0, $currentKey);
        }

        $this->storage->store()->undo($currentKey);

        return new RateLimitDecision(false, $limit, 0, self::retryAfter($limit, $window, $elapsed, $previous, $current - 1));
    }

    /**
     * Take back a request that was allowed here and then refused by another
     * limit: a client blocked for a minute must not burn its hour meanwhile.
     */
    public function release(RateLimitDecision $decision): void
    {
        if ($decision->allowed && $decision->counter !== null) {
            $this->storage->store()->undo($decision->counter);
        }
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

    /**
     * Which application counts: a key derived from APP_SECRET, the same on
     * every server of one app. Rotating the secret starts the counters over.
     */
    private static function appScope(): string
    {
        try {
            return bin2hex(substr(AppSecret::derive(AppSecret::current(), 'rate-limit'), 0, 8));
        } catch (\LogicException) {
            // No APP_SECRET outside dev: refused everywhere else, but a limit
            // must not take the request down with it.
            return 'app:' . (string) Environment::getEnvValue('APP_NAME', '');
        }
    }


    private function subject(RateLimitKey|string $by): string
    {
        if (is_string($by)) {
            return 'key:' . $by;
        }

        if ($by === RateLimitKey::User && isset($this->authContext) && !$this->authContext->isGuest()) {
            $id = $this->authContext->getUser()?->getId();
            if ($id !== null && $id !== '') {
                // User 42 of one tenant is not user 42 of another. An address
                // stays counted across tenants: per tenant, a client guessing
                // passwords got a fresh budget on every tenant's host.
                $tenant = isset($this->tenants) ? TenantContextAccess::tenantIdOrDefault($this->tenants->tryGet()) : 'default';

                return 'user:' . $tenant . ':' . $id;
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

        // ::ffff:1.2.3.4 is an IPv4 client on a dual-stack socket. Its first
        // 64 bits are zeros for EVERY such client: as a /64 they were one.
        if (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($binary, 12));
        }

        return (string) inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
