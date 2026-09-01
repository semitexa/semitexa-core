<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

/**
 * Says whether this worker has been asked to exit, for long-running work that must stand
 * down on its own.
 *
 * Exists because `Swoole\Coroutine::cancel()` is not a signal — it is an attempt, and a
 * refused attempt leaves nothing behind. MEASURED on Swoole 6.2.0 under SWOOLE_HOOK_ALL:
 * a coroutine looping over `file_get_contents` was cancelled while parked on a read;
 * `cancel()` returned false and `isCanceled()` stayed FALSE for the rest of the loop —
 * the coroutine read all 400 remaining files and finished normally. Reading the
 * cancellation flag, which is the documented way to cooperate with a drain, does not work
 * for the one coroutine species that actually holds workers hostage. Replacing that probe
 * with this flag in the same experiment stood the loop down at file 17 of 400.
 *
 * That is why this is a plain static and not {@see \Semitexa\Core\Support\CoroutineLocal}.
 * The trap that motivated the CoroutineLocal sweep is REQUEST-scoped state on a
 * worker-lifetime object, where one coroutine's value leaks into another's request.
 * "This worker is going away" is the opposite: it is worker-scoped by nature, every
 * coroutine in the process must see it, and it is written exactly once by the lifecycle
 * handler. Coroutine isolation here would defeat the purpose.
 *
 * Set once from `WorkerExit`. Nothing clears it in production — the process ends moments
 * later, and a worker that un-drained would be a worse bug than the one this fixes.
 */
final class WorkerDrainSignal
{
    private static bool $draining = false;

    /** Called from the lifecycle handler the moment the worker is told to exit. */
    public static function begin(): void
    {
        self::$draining = true;
    }

    /**
     * True once the worker is on its way out. Long scans check this between units of work
     * and abandon the rest; see {@see \Semitexa\Core\Discovery\ClassDiscovery::shouldAbortScan()}.
     */
    public static function isDraining(): bool
    {
        return self::$draining;
    }

    /**
     * Tests only. Production never un-drains, but a test process outlives many simulated
     * worker lifetimes and would otherwise carry the flag into unrelated cases.
     */
    public static function reset(): void
    {
        self::$draining = false;
    }
}
