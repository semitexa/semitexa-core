<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * Reads/writes session data to Swoole Table. Used by Session when Redis is not configured.
 *
 * The table has a fixed capacity and nothing expires in it by itself, so a
 * write that finds no room reclaims it (tk-swoole-session-table-exhaustion-dos):
 * expired rows are deleted first, then the rows that expire soonest, a bounded
 * number of them. A write that still finds no room throws. All of this runs
 * only when set() fails; a write that fits costs what it did before.
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

    /** @param \Swoole\Table|null $table A private table (tests); the server's shared one when null. */
    public function __construct(private readonly ?\Swoole\Table $table = null)
    {
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
            $table->delete($sessionId);
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

        // Silenced: a full table answers false AND raises a warning, and the
        // false is handled right here.
        if (@$table->set($sessionId, $row)) {
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
     * Not atomic: Swoole\Table has no compare-and-set, and its row lock is not
     * offered to PHP. No coroutine switches between the get() and the set(), so
     * the gap is open only to another worker process, for the length of two
     * table calls: an id retired in exactly that gap is written over. Closing
     * it takes a lock shared by all workers around every session write.
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

        return @$table->set($sessionId, [
            self::COLUMN_DATA => json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
            self::COLUMN_EXPIRES => $lifetimeSeconds > 0 ? time() + $lifetimeSeconds : 0,
        ]);
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
        if ($expired !== [] && @$table->set($sessionId, $row)) {
            return true;
        }

        asort($live);
        $batch = max(self::EVICTION_MIN_BATCH, count($live) >> self::EVICTION_SHIFT);
        $victims = array_slice($live, 0, $batch * self::EVICTION_ROUNDS, true);
        foreach (array_chunk($victims, $batch, true) as $round) {
            foreach ($round as $key => $scanned) {
                $this->deleteIfUnchanged($table, (string) $key, $scanned);
            }
            if (@$table->set($sessionId, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deletes a row only while it still carries the expiry the scan saw:
     * another worker may have refreshed that session since, and a session in
     * use is not the one to drop.
     *
     * Not atomic. Swoole\Table has no compare-and-delete, so a refresh that
     * lands between this read and the delete is still lost. Closing that gap
     * takes a lock every session write would pay for; the re-read costs only
     * the failure path, and leaves one session at risk for the length of two
     * table calls, while the table is full.
     */
    private function deleteIfUnchanged(\Swoole\Table $table, string $key, int $scanned): void
    {
        $current = $table->get($key, self::COLUMN_EXPIRES);
        if (!is_int($current)) {
            return;
        }
        if (($current > 0 ? $current : PHP_INT_MAX) === $scanned) {
            $table->delete($key);
        }
    }

    public function destroy(string $sessionId): void
    {
        $table = $this->table ?? SwooleSessionTableHolder::getTable();
        if ($table !== null) {
            $table->delete($sessionId);
        }
    }
}
