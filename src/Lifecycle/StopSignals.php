<?php

declare(strict_types=1);

namespace Semitexa\Core\Lifecycle;

/**
 * SIGTERM and SIGINT ask a long-running command to stop after the work in
 * hand, instead of killing it in the middle: `docker stop` sends SIGTERM,
 * and a worker cut off mid-job left that job to run again from the start
 * after its lease ran out, half of it done once.
 *
 * The handler only calls $stop; the command finishes what it holds, sees the
 * stop and exits 0. A second signal ends it at once (Ctrl-C twice). Needs the
 * pcntl extension (in the scaffold image); without it nothing is installed
 * and a signal ends the command as before.
 */
final class StopSignals
{
    /**
     * @param \Closure(): void $stop asks the loop to end after the work in hand
     * @param (\Closure(string): void)|null $say told when a stop is asked for
     * @param string $inHand what is finished first, for $say ("the job in hand")
     * @return bool whether the handlers were installed
     */
    public static function install(\Closure $stop, ?\Closure $say = null, string $inHand = 'the work in hand'): bool
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')
            || !defined('SIGTERM') || !defined('SIGINT')) {
            return false;
        }

        pcntl_async_signals(true);
        $handler = static function (int $signal) use ($stop, $say, $inHand): void {
            // The second signal is not a request: Ctrl-C twice ends a worker
            // stuck in a long job, as it did before.
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            $stop();
            if ($say !== null) {
                $say(sprintf('Signal %d: stopping after %s (again to stop at once).', $signal, $inHand));
            }
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        return true;
    }
}
