<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * SIGTERM and SIGINT stop a worker's consume loop after the message in hand.
 *
 * `docker stop` sends SIGTERM, and a worker that did not catch it died in the
 * middle of a job: the job ran again only after its lease or ack wait (300 s),
 * having done half of its work once. The handler only asks the transport to
 * stop; the job in hand runs to its end, then consume() returns and the
 * command exits 0. A second signal ends it at once. Needs the pcntl extension (in the scaffold image); without
 * it, or with a transport that cannot stop, nothing is installed and a signal
 * ends the worker as before.
 */
final class QueueStopSignals
{
    /**
     * @param (\Closure(string): void)|null $say told when a stop is asked for
     * @return bool whether the handlers were installed
     */
    public static function install(QueueTransportInterface $transport, ?\Closure $say = null): bool
    {
        if (!$transport instanceof StoppableQueueTransportInterface
            || !function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')
            || !defined('SIGTERM') || !defined('SIGINT')) {
            return false;
        }

        pcntl_async_signals(true);
        $handler = static function (int $signal) use ($transport, $say): void {
            // The second signal is not a request: Ctrl-C twice ends a worker
            // stuck in a long job, as it did before.
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            $transport->stop();
            if ($say !== null) {
                $say(sprintf('Signal %d: stopping after the message in hand (again to stop at once).', $signal));
            }
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        return true;
    }
}
