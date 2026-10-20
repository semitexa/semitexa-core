<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * The coroutine-local keys that make up a request's context beyond the
 * container's (tenant, signed-in user, locale, theme...), so work that runs
 * after the response, or in a coroutine of its own, can run with the
 * request's.
 *
 * Each store that keeps such a value names its keys here when it first sets
 * one ({@see carry()}); core knows none of them. Only what a store names is
 * carried: a coroutine's context also holds what must never be shared with
 * another coroutine (a database connection it checked out, say), so it is
 * not copied whole.
 *
 * The request clears much of it once the response is out
 * ({@see PerRequestStateRegistry}), and a new coroutine starts without any:
 * {@see capture()} while the request runs, {@see runWith()} where the work runs.
 */
final class CarriedContext
{
    /** @var array<string, true> */
    #[WorkerState('Keys named once per worker by the stores that own them; the values are per coroutine.')]
    private static array $keys = [];

    /** Idempotent: a store may name its keys on every set. */
    public static function carry(string ...$keys): void
    {
        foreach ($keys as $key) {
            self::$keys[$key] = true;
        }
    }

    /** @return array<string, mixed> each named key this coroutine holds, and its value */
    public static function capture(): array
    {
        $captured = [];
        foreach (array_keys(self::$keys) as $key) {
            if (CoroutineLocal::has($key)) {
                $captured[$key] = CoroutineLocal::get($key);
            }
        }

        return $captured;
    }

    /**
     * Runs $work with exactly $captured for the named keys (one it does not
     * hold is removed), then puts back what this coroutine held before.
     *
     * @template T
     * @param array<string, mixed> $captured from {@see capture()}
     * @param \Closure(): T        $work
     * @return T
     */
    public static function runWith(array $captured, \Closure $work): mixed
    {
        $previous = self::capture();
        self::replace($captured);
        try {
            return $work();
        } finally {
            self::replace($previous);
        }
    }

    /** @param array<string, mixed> $values */
    private static function replace(array $values): void
    {
        foreach (array_keys(self::$keys) as $key) {
            if (array_key_exists($key, $values)) {
                CoroutineLocal::set($key, $values[$key]);
            } else {
                CoroutineLocal::remove($key);
            }
        }
    }
}
