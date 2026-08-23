<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

use Swoole\Table;

/**
 * Counts abnormal worker exits per worker id, so a worker that keeps dying at boot can
 * be told to stop repeating whatever is killing it.
 *
 * Swoole always respawns a dead worker and there is no PHP-side way to refuse — which is
 * exactly right for a worker that crashed once, and a trap for a worker that dies on the
 * BOOT path, because the replacement runs the same boot and dies the same way, forever,
 * at whatever CPU the doomed boot costs. Nothing in the loop reads as an error: each
 * individual worker looks like a single unlucky crash, the server keeps serving on its
 * surviving workers, and the only trace is one `worker_error` line per cycle.
 *
 * Measured before this existed: an OS planner warm-up aimed at an LLM host that had gone
 * away respawned worker 0 1703 times over two days, holding ~60-75% of a core the whole
 * time, and nothing anywhere said so.
 *
 * The manager keeps the tally, because {@see SwooleEvent::WorkerError} fires THERE — the
 * manager is not forked per worker, so it is the one process that sees every crash of
 * every worker. The counts live in a {@see Table} allocated before `start()` so that the
 * respawned worker can read its own history across the fork; optional boot work asks
 * {@see \Semitexa\Core\Server\Lifecycle\ServerLifecycleContext::workerIsCrashLooping()}
 * and stands down.
 *
 * {@see WINDOW_SECONDS} is what separates a crash loop from bad luck: crashes only
 * accumulate while they keep arriving within that distance of each other, so a worker
 * that dies once a day never trips, and a tripped worker that stops crashing ages back
 * to a clean slate on its own.
 */
final class WorkerCrashLoopBreaker
{
    /** Consecutive crashes inside the window before optional boot work stands down. */
    public const TRIP_THRESHOLD = 3;

    /** A crash further than this from the previous one starts the count over. */
    public const WINDOW_SECONDS = 300;

    /**
     * How often to repeat the alarm once tripped. One line at the moment of tripping is
     * invisible to an operator who starts looking a day later, and a line per crash just
     * doubles the noise the loop already makes.
     */
    public const REALARM_EVERY = 25;

    /** Swoole Table: 32 bits is plenty for a crash counter. */
    private const COUNT_COLUMN_SIZE = 4;

    /** Unix timestamps need 64 bits — a 32-bit column would wrap in 2038. */
    private const TIMESTAMP_COLUMN_SIZE = 8;

    /** Never size the table below this; a handful of rows costs nothing. */
    private const MIN_ROWS = 64;

    private Table $table;

    public function __construct(int $workerCount)
    {
        $this->table = new Table(max(self::MIN_ROWS, $workerCount));
        $this->table->column('crashes', Table::TYPE_INT, self::COUNT_COLUMN_SIZE);
        $this->table->column('last_at', Table::TYPE_INT, self::TIMESTAMP_COLUMN_SIZE);
        $this->table->create();
    }

    /**
     * Record one abnormal exit and return how many consecutive crashes this worker has
     * now had inside the window. Called from the manager, on every worker error.
     */
    public function recordCrash(int $workerId, int $now): int
    {
        $crashes = $this->countAt($workerId, $now) + 1;
        $this->table->set((string) $workerId, ['crashes' => $crashes, 'last_at' => $now]);

        return $crashes;
    }

    /**
     * Consecutive crashes still inside the window — 0 for a worker booting clean. Called
     * from the worker itself, which reads the tally its predecessors left behind.
     */
    public function crashCount(int $workerId, int $now): int
    {
        return $this->countAt($workerId, $now);
    }

    /** True once this worker has crashed often enough that its boot is suspect. */
    public function isTripped(int $workerId, int $now): bool
    {
        return $this->countAt($workerId, $now) >= self::TRIP_THRESHOLD;
    }

    /**
     * Whether a tally of $crashes should raise the alarm now: once when it crosses the
     * threshold, then every {@see REALARM_EVERY} crashes so a loop stays visible.
     */
    public static function shouldAlarm(int $crashes): bool
    {
        if ($crashes < self::TRIP_THRESHOLD) {
            return false;
        }

        return $crashes === self::TRIP_THRESHOLD
            || ($crashes - self::TRIP_THRESHOLD) % self::REALARM_EVERY === 0;
    }

    private function countAt(int $workerId, int $now): int
    {
        $row = $this->table->get((string) $workerId);
        if (!is_array($row)) {
            return 0;
        }

        $lastAt = (int) ($row['last_at'] ?? 0);
        if ($lastAt <= 0 || ($now - $lastAt) > self::WINDOW_SECONDS) {
            return 0;
        }

        return (int) ($row['crashes'] ?? 0);
    }
}
