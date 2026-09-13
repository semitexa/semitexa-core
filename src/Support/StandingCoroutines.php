<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

/**
 * Coroutines that are SUPPOSED to sit parked for the life of the worker, and
 * what each of them is waiting for.
 *
 * ## Why this exists
 *
 * The Observatory judges a long-lived coroutine by what is behind it: an `sse`
 * process means a session, an `http` process means a stuck request, and nothing
 * behind it means a leak. A framework coroutine born at worker start — a
 * pub/sub receiver, a lease heartbeat, a queue consumer — has nothing behind
 * it, so it lands in the leak bucket and is wrong there. Six of them on an idle
 * machine teach an operator to ignore the hung count, and then it cannot warn
 * about the seventh that is real.
 *
 * So standing work says so, in its own words, where the snapshot can read it.
 *
 * ## Shape
 *
 * A worker-wide map keyed by coroutine id. That is deliberately NOT
 * {@see CoroutineLocal}: this is not per-request state that must not leak
 * between coroutines, it is a register of coroutines, read by a different
 * coroutine than the ones that write it.
 *
 * Entries are removed when the coroutine ends — by a deferred callback where
 * the runtime allows one, and otherwise by the reader, which is the only thing
 * that knows which coroutines still exist. Both paths matter: this runtime
 * cancels parked coroutines on worker exit, and a cancelled park does not
 * always run what it deferred.
 *
 * Everything here is a no-op outside Swoole. The CLI and the test suite run
 * this code, and an observability aid that throws where it cannot observe
 * would be worse than the blindness it fixes.
 */
final class StandingCoroutines
{
    /** A reason is a sentence for a human, not a payload. */
    private const MAX_REASON = 200;
    private const MAX_LABEL = 60;

    /** @var array<int, array{label: string, reason: string, since: float}> */
    private static array $standing = [];

    /**
     * Declare the CURRENT coroutine as standing work, and arrange for the
     * declaration to be dropped when it ends.
     */
    public static function declare(string $label, string $reason): void
    {
        $cid = self::currentCid();
        if ($cid === null) {
            return;
        }

        self::declareFor($cid, $label, $reason);

        // Best effort: a coroutine that is cancelled rather than returning may
        // never run this, which is why the reader prunes as well.
        if (function_exists('\\Swoole\\Coroutine\\defer')) {
            \Swoole\Coroutine\defer(static fn () => self::forgetFor($cid));
        }
    }

    /** Drop the current coroutine's declaration. */
    public static function forget(): void
    {
        $cid = self::currentCid();
        if ($cid !== null) {
            self::forgetFor($cid);
        }
    }

    public static function declareFor(int $cid, string $label, string $reason): void
    {
        self::$standing[$cid] = [
            'label' => mb_substr(trim($label), 0, self::MAX_LABEL),
            'reason' => mb_substr(trim($reason), 0, self::MAX_REASON),
            'since' => microtime(true),
        ];
    }

    public static function forgetFor(int $cid): void
    {
        unset(self::$standing[$cid]);
    }

    /**
     * Everything currently declared.
     *
     * Pass the coroutine ids that still exist and the declarations of the ones
     * that do not are dropped for good, rather than filtered out of a single
     * answer. The reader is the only caller that knows the live set, so it is
     * the one that gets to say.
     *
     * @param list<int>|null $liveCids
     * @return array<int, array{label: string, reason: string, since: float}>
     */
    public static function all(?array $liveCids = null): array
    {
        if ($liveCids !== null) {
            $live = array_flip($liveCids);
            foreach (array_keys(self::$standing) as $cid) {
                if (!isset($live[$cid])) {
                    unset(self::$standing[$cid]);
                }
            }
        }

        return self::$standing;
    }

    /** @internal Test seam: a worker never needs to forget everything at once. */
    public static function reset(): void
    {
        self::$standing = [];
    }

    private static function currentCid(): ?int
    {
        if (!class_exists(\Swoole\Coroutine::class, false)) {
            return null;
        }

        $cid = (int) \Swoole\Coroutine::getCid();

        return $cid > 0 ? $cid : null;
    }
}
