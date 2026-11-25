<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Reads/writes session data to Swoole Table. Used by Session when Redis is not configured.
 *
 * The table has a fixed capacity and nothing expires in it by itself, so a
 * write that finds no room reclaims it (tk-swoole-session-table-exhaustion-dos):
 * expired rows are deleted first, then the rows that expire soonest, a bounded
 * number of them. A write that still finds no room throws. All of this runs
 * only when set() fails; a write that fits costs what it did before.
 *
 * Every change of a row (a write, a held write, a delete, an eviction) is made
 * under a lock on that row's session id, shared by all workers
 * ({@see SwooleSessionTableHolder::getLocks()}): the check and the write of
 * {@see writeIfHeld()} are one step for every other worker. Held for table
 * calls only, with nothing that lets another coroutine run, one lock at a
 * time, never two. Without locks (a private table given none, the CLI) the
 * calls run as they are: one process, and nothing to wait for.
 */
final class SwooleTableSessionHandler implements HeldSessionWriterInterface
{
    private const COLUMN_DATA = 'data';
    private const COLUMN_EXPIRES = 'expires_at';
    private const DEFAULT_LIFETIME = 3600; // 1 hour
    /**
     * Live rows evicted per round: 1/32 of the table, so the full scan a
     * failed write costs is paid once per that many new sessions, not on
     * every one of a flood.
     */
    private const EVICTION_SHIFT = 5;
    private const EVICTION_MIN_BATCH = 4;
    /**
     * More than one round because a freed row does not always free the slot
     * the new key hashes to. Four rounds bound the eviction at 1/8 of the table.
     */
    private const EVICTION_ROUNDS = 4;
    /**
     * A lock is held for a few table calls, so a wait this long means its
     * holder died with it (a worker killed inside those calls), or, once in a
     * while, that the holder was not scheduled for that long on a saturated
     * CPU. A change of a session a browser holds ({@see writeIfHeld()}) is
     * then not made: a holder that is only slow would write over anything
     * written beside it. On a stripe whose holder died, that is every save,
     * sign-in and sign-out of its ids (1 in 64) until the server restarts, and
     * a warning says so at most once a minute per worker. A write of a new id
     * and a delete go on: no browser holds those ids yet. Waiting for ever
     * would stop every worker on its next session instead.
     */
    private const LOCK_WAIT_SECONDS = 0.1;
    /** Between two tries of a held stripe; a sleep, so a coroutine gives way. */
    private const LOCK_RETRY_SECONDS = 0.001;
    private const LOCK_WARNING_INTERVAL_SECONDS = 60;

    private static int $lockWarnedAt = 0;

    /**
     * @param \Swoole\Table|null $table A private table (tests); the server's shared one when null.
     * @param list<\Swoole\Lock>|null $locks The locks of a private table
     *        ({@see SwooleSessionTableHolder::createLocks()}); none when null. Not
     *        used with the server's table, which has its own.
     */
    public function __construct(
        private readonly ?\Swoole\Table $table = null,
        private readonly ?array $locks = null,
    ) {
    }

    public function read(string $sessionId): array
    {
        $table = $this->table ?? SwooleSessionTableHolder::getTable();
        if ($table === null) {
            return [];
        }

        $row = $table->get($sessionId);
        if ($row === false) {
            return [];
        }

        $expires = (int) ($row[self::COLUMN_EXPIRES] ?? 0);
        if ($expires > 0 && $expires < time()) {
            // Only the row that was read: one written since is another session.
            $this->deleteIfUnchanged($table, $sessionId, $expires);
            return [];
        }

        $raw = $row[self::COLUMN_DATA] ?? '';
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = self::DEFAULT_LIFETIME): void
    {
        $table = $this->table ?? SwooleSessionTableHolder::getTable();
        if ($table === null) {
            return;
        }

        $expires = $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0;
        $raw = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $row = [
            self::COLUMN_DATA => $raw,
            self::COLUMN_EXPIRES => $expires,
        ];

        if ($this->set($table, $sessionId, $row)) {
            return;
        }

        if (!$this->reclaimAndSet($table, $sessionId, $row)) {
            throw new SessionStoreFullException(sprintf(
                'The session table is full (%d rows) and no row could be reclaimed for a new session. '
                . 'Raise SWOOLE_SESSION_TABLE_SIZE or configure Redis (REDIS_HOST).',
                $table->count(),
            ));
        }
    }

    /**
     * One step for every worker of the server: the row is read, checked and
     * written under the lock of its id, and so is every other change of that
     * row ({@see write()}, {@see destroy()}, the deletes of a reclaim, another
     * writeIfHeld()). An id retired in another worker is either retired before
     * the read, and then nothing is written, or after the write, and then the
     * marker replaces it. No coroutine switches inside.
     *
     * Two steps again only without locks (a private table given none, the
     * CLI: one process). A call whose lock did not come in
     * {@see LOCK_WAIT_SECONDS} writes nothing and answers false.
     *
     * The set() is on a key the get() just found, so it needs no room and
     * nothing is reclaimed for it.
     */
    public function writeIfHeld(string $sessionId, array $data, int $lifetimeSeconds, array $unless): bool
    {
        $table = $this->table ?? SwooleSessionTableHolder::getTable();
        if ($table === null) {
            return false;
        }

        // Encoded before the lock: it can throw, and it is not a table call.
        $new = [
            self::COLUMN_DATA => json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            self::COLUMN_EXPIRES => $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0,
        ];

        $lock = $this->lock($sessionId);
        try {
            // Without its lock nothing is written, not even a retirement: the
            // holder may be alive, only not scheduled, and would write the
            // session it read over a marker written beside it (CodeRabbit on
            // semitexa-core#274). Session reads the false as a retired id: a
            // save sends that response no cookie, and a sign-in or a sign-out
            // is answered "try again".
            if ($lock === false) {
                return false;
            }
            $row = $table->get($sessionId);
            if (!is_array($row)) {
                return false;
            }
            $expires = $row[self::COLUMN_EXPIRES] ?? 0;
            if (is_int($expires) && $expires > 0 && $expires < time()) {
                return false;
            }
            $raw = $row[self::COLUMN_DATA] ?? '';
            $held = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($held) || $held === [] || $held === $unless) {
                return false;
            }

            return @$table->set($sessionId, $new);
        } finally {
            self::release($lock);
        }
    }

    /**
     * The lock of $sessionId's stripe, taken; null when there are no locks to
     * take (a private table given none, the CLI), false when this one did not
     * come in {@see LOCK_WAIT_SECONDS}. The caller releases it
     * ({@see release()}), in a finally, and takes no second one before that:
     * the mutex is not re-entrant, and two held in different orders by two
     * workers never come back.
     *
     * The wait does not block the worker: a held stripe is tried again after
     * a sleep that, in a coroutine, lets the worker's other coroutines run. A
     * holder runs table calls only, so the second try nearly always gets it;
     * only a holder that died with it (a worker killed inside those calls)
     * makes a caller wait the whole time, and then it is said, at most once a
     * minute per worker.
     */
    private function lock(string $sessionId): \Swoole\Lock|false|null
    {
        $locks = $this->table !== null ? ($this->locks ?? []) : SwooleSessionTableHolder::getLocks();
        if ($locks === []) {
            return null;
        }
        $stripe = crc32($sessionId) % count($locks);
        $lock = $locks[$stripe];

        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        $inCoroutine = \Swoole\Coroutine::getCid() > 0;
        do {
            // @phpstan-ignore arguments.count (Swoole 6.1 gave lock() its operation; the stubs are 5.1's)
            if ($lock->lock(LOCK_EX | LOCK_NB)) {
                return $lock;
            }
            if ($inCoroutine) {
                \Swoole\Coroutine::sleep(self::LOCK_RETRY_SECONDS);
            } else {
                usleep((int) (self::LOCK_RETRY_SECONDS * 1_000_000));
            }
        } while (microtime(true) < $deadline);

        // Nothing is held here, so the logger may do what it likes.
        $now = time();
        if ($now - self::$lockWarnedAt >= self::LOCK_WARNING_INTERVAL_SECONDS) {
            self::$lockWarnedAt = $now;
            StaticLoggerBridge::warning('session', sprintf(
                'Session lock stripe %d did not come in %.1f s: that change of a held session is dropped '
                . '(a save is lost, a sign-in or a sign-out is answered "try again"). '
                . 'If this repeats, a worker died holding it: run server:restart.',
                $stripe,
                self::LOCK_WAIT_SECONDS,
            ), ['stripe' => $stripe]);
        }

        return false;
    }

    private static function release(\Swoole\Lock|false|null $lock): void
    {
        if ($lock instanceof \Swoole\Lock) {
            $lock->unlock();
        }
    }

    /**
     * set() under the lock of its id.
     *
     * @param array{data: string, expires_at: int} $row
     */
    private function set(\Swoole\Table $table, string $sessionId, array $row): bool
    {
        $lock = $this->lock($sessionId);
        try {
            // Silenced: a full table answers false AND raises a warning, and
            // the false is handled by the caller.
            return @$table->set($sessionId, $row);
        } finally {
            self::release($lock);
        }
    }

    /**
     * The failure path of write(): one scan of the table, then deletes.
     *
     * @param array{data: string, expires_at: int} $row
     */
    private function reclaimAndSet(\Swoole\Table $table, string $sessionId, array $row): bool
    {
        $now = time();
        /** @var array<string, int> $expired expires_at by session id */
        $expired = [];
        /** @var array<string, int> $live expires_at by session id */
        $live = [];
        // Keys are collected first: rows are not deleted under the iterator.
        foreach ($table as $key => $stored) {
            if (!is_string($key) || !is_array($stored)) {
                continue;
            }
            $expires = $stored[self::COLUMN_EXPIRES] ?? 0;
            $expires = is_int($expires) ? $expires : 0;
            if ($expires > 0 && $expires < $now) {
                $expired[$key] = $expires;
            } else {
                // 0 never expires, so it is the last to be evicted.
                $live[$key] = $expires > 0 ? $expires : PHP_INT_MAX;
            }
        }

        foreach ($expired as $key => $scanned) {
            $this->deleteIfUnchanged($table, (string) $key, $scanned);
        }
        if ($expired !== [] && $this->set($table, $sessionId, $row)) {
            return true;
        }

        asort($live);
        $batch = max(self::EVICTION_MIN_BATCH, count($live) >> self::EVICTION_SHIFT);
        $victims = array_slice($live, 0, $batch * self::EVICTION_ROUNDS, true);
        foreach (array_chunk($victims, $batch, true) as $round) {
            foreach ($round as $key => $scanned) {
                $this->deleteIfUnchanged($table, (string) $key, $scanned);
            }
            if ($this->set($table, $sessionId, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes a row only while it still carries the expiry the caller saw:
     * another worker may have refreshed that session since, and a session in
     * use is not the one to drop. The re-read and the delete are under the
     * row's lock, so a refresh lands before both or after both, and without
     * the lock nothing is deleted.
     */
    private function deleteIfUnchanged(\Swoole\Table $table, string $key, int $scanned): void
    {
        $lock = $this->lock($key);
        try {
            // Not without its lock: a holder that is only slow may be writing
            // the marker that retires this id, and the delete would take the
            // marker away (CodeRabbit on semitexa-core#274). The row stays;
            // a full table then answers this write as full.
            if ($lock === false) {
                return;
            }
            $current = $table->get($key, self::COLUMN_EXPIRES);
            if (!is_int($current)) {
                return;
            }
            if (($current > 0 ? $current : PHP_INT_MAX) === $scanned) {
                $table->delete($key);
            }
        } finally {
            self::release($lock);
        }
    }

    public function destroy(string $sessionId): void
    {
        $table = $this->table ?? SwooleSessionTableHolder::getTable();
        if ($table === null) {
            return;
        }

        $lock = $this->lock($sessionId);
        try {
            $table->delete($sessionId);
        } finally {
            self::release($lock);
        }
    }
}
