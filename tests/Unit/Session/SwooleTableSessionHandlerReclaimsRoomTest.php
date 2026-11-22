<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Session\SessionStoreFullException;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Swoole\Table;

/**
 * tk-swoole-session-table-exhaustion-dos: the session table has a fixed
 * capacity, expired rows were never swept and a set() that found no room was
 * ignored. Once cookieless requests had filled it, every new session (a login,
 * a CSRF token) was silently dropped until the server restarted.
 *
 * Every test runs on a private table of its own, never the server's.
 */
final class SwooleTableSessionHandlerReclaimsRoomTest extends TestCase
{
    /** Far more than the smallest table holds, so filling always reaches "full". */
    private const FILL_ATTEMPTS = 2000;

    #[Test]
    public function a_full_table_of_expired_rows_accepts_a_new_session(): void
    {
        $table = $this->table();
        $rows = $this->fillRaw($table, static fn (int $i): int => time() - 10);
        self::assertGreaterThan(0, $rows);

        $handler = new SwooleTableSessionHandler($table);
        // Several, not one: a single key can land on a free slot by luck.
        for ($i = 0; $i < 10; $i++) {
            $handler->write($this->id('new', $i), ['user' => $i], 3600);
        }

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(['user' => $i], $handler->read($this->id('new', $i)));
        }
        self::assertSame(10, $table->count(), 'the expired rows were swept, not kept beside the new ones');
    }

    #[Test]
    public function a_full_table_of_live_rows_evicts_the_ones_that_expire_soonest(): void
    {
        $table = $this->table();
        // Row $i expires later than row $i - 1.
        $rows = $this->fillRaw($table, static fn (int $i): int => time() + 1000 + $i);
        $stored = [];
        foreach ($table as $key => $row) {
            self::assertIsString($key);
            self::assertIsArray($row);
            self::assertIsInt($row['expires_at']);
            $stored[$key] = $row['expires_at'];
        }
        self::assertCount($rows, $stored);

        $handler = new SwooleTableSessionHandler($table);
        // Keys the full table refuses, so each write below has to make room.
        $newIds = [];
        for ($i = 0; count($newIds) < 5; $i++) {
            $id = $this->id('new', $i);
            if (@$table->set($id, ['data' => '{}', 'expires_at' => time() + 90000])) {
                $table->delete($id);
                continue;
            }
            $newIds[] = $id;
        }

        $handler->write($newIds[0], ['user' => 0], 90000);
        $afterOne = $rows - $this->surviving($table, $stored);
        self::assertGreaterThan(0, $afterOne);
        self::assertLessThanOrEqual(16, $afterOne, 'eviction is bounded: four rounds of four rows on a table this small');

        foreach ($newIds as $n => $id) {
            $handler->write($id, ['user' => $n], 90000);
        }
        foreach ($newIds as $n => $id) {
            self::assertSame(['user' => $n], $handler->read($id), 'a new session is stored, and no earlier new one was evicted for it');
        }

        $evicted = [];
        $kept = [];
        foreach ($stored as $key => $expires) {
            if ($table->exists($key)) {
                $kept[] = $expires;
            } else {
                $evicted[] = $expires;
            }
        }
        self::assertNotEmpty($kept);
        self::assertNotEmpty($evicted);
        self::assertLessThan(min($kept), max($evicted), 'every evicted row expired sooner than every row that was kept');
    }

    #[Test]
    public function a_write_that_finds_no_room_at_all_fails_visibly(): void
    {
        // A table that refuses every row, whatever is evicted from it.
        $table = new class (16) extends Table {
            /** @param array<string, mixed> $value */
            public function set(string $key, array $value): bool
            {
                return false;
            }
        };
        $table->column('data', Table::TYPE_STRING, 256);
        $table->column('expires_at', Table::TYPE_INT, 8);
        $table->create();

        $this->expectException(SessionStoreFullException::class);
        $this->expectExceptionMessage('SWOOLE_SESSION_TABLE_SIZE');
        (new SwooleTableSessionHandler($table))->write($this->id('new', 0), ['user' => 1], 3600);
    }

    /**
     * Another worker refreshes a session after the reclaim scanned it as
     * expired: the row now holds a session in use and must not be deleted.
     */
    #[Test]
    public function a_row_refreshed_between_the_scan_and_the_delete_survives(): void
    {
        /** @var \ArrayObject<int, string> $refreshed */
        $refreshed = new \ArrayObject();
        // Plays the other worker: at the first delete of the reclaim, it
        // rewrites a different expired row with a future expiry.
        $table = new class (16, $refreshed) extends Table {
            /** @param \ArrayObject<int, string> $refreshed */
            public function __construct(int $size, private readonly \ArrayObject $refreshed)
            {
                parent::__construct($size);
            }

            public function delete(string $key): bool
            {
                if (count($this->refreshed) === 0) {
                    foreach ($this as $other => $row) {
                        if (is_string($other) && $other !== $key) {
                            parent::set($other, ['data' => '{"user":"back"}', 'expires_at' => time() + 3600]);
                            $this->refreshed->append($other);
                            break;
                        }
                    }
                }

                return parent::delete($key);
            }
        };
        $table->column('data', Table::TYPE_STRING, 256);
        $table->column('expires_at', Table::TYPE_INT, 8);
        $table->create();
        $rows = $this->fillRaw($table, static fn (int $i): int => time() - 10);

        $handler = new SwooleTableSessionHandler($table);
        $handler->write($this->id('new', 0), ['user' => 0], 3600);

        $kept = $refreshed->getArrayCopy();
        self::assertCount(1, $kept, 'the reclaim ran and a row was refreshed under it');
        self::assertSame(['user' => 'back'], $handler->read($kept[0]), 'the refreshed session is kept');
        self::assertSame(['user' => 0], $handler->read($this->id('new', 0)));
        self::assertLessThan($rows, $table->count(), 'the rows that stayed expired were still swept');
    }

    #[Test]
    public function nothing_is_lost_while_there_is_room(): void
    {
        $table = $this->table(1024);
        $handler = new SwooleTableSessionHandler($table);

        for ($i = 0; $i < 40; $i++) {
            $handler->write($this->id('roomy', $i), ['user' => $i], 3600);
        }
        // Rewriting a stored session never needs a new row.
        $handler->write($this->id('roomy', 0), ['user' => 'again'], 3600);

        self::assertSame(40, $table->count());
        self::assertSame(['user' => 'again'], $handler->read($this->id('roomy', 0)));
        for ($i = 1; $i < 40; $i++) {
            self::assertSame(['user' => $i], $handler->read($this->id('roomy', $i)));
        }
    }

    private function table(int $size = 16): Table
    {
        $table = new Table($size);
        $table->column('data', Table::TYPE_STRING, 256);
        $table->column('expires_at', Table::TYPE_INT, 8);
        $table->create();

        return $table;
    }

    /**
     * Fills the table to the point where set() refuses a new key, bypassing the
     * handler so nothing is reclaimed on the way.
     *
     * @param \Closure(int): int $expiresAt
     */
    private function fillRaw(Table $table, \Closure $expiresAt): int
    {
        $refusedOnce = false;
        for ($i = 0; $i < self::FILL_ATTEMPTS; $i++) {
            if (!@$table->set($this->id('old', $i), ['data' => '{}', 'expires_at' => $expiresAt($i)])) {
                $refusedOnce = true;
            }
        }
        self::assertTrue($refusedOnce, 'the fixture table must be full');

        return $table->count();
    }

    /** @param array<string, int> $stored */
    private function surviving(Table $table, array $stored): int
    {
        return count(array_filter(array_keys($stored), static fn (string $key): bool => $table->exists($key)));
    }

    private function id(string $prefix, int $i): string
    {
        return md5($prefix . $i);
    }
}
