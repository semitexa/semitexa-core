<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Session\SessionStoreFullException;
use Semitexa\Core\Session\SwooleSessionTableHolder;
use Semitexa\Core\Session\SwooleTableSessionHandler;
use Semitexa\Core\Tests\Support\StaticState;
use Swoole\Atomic;
use Swoole\Lock;
use Swoole\Table;

/**
 * tk-session-inflight-request-rewrites-a-retired-session, on the Swoole table:
 * writeIfHeld() was a get() and then a set(), and a sign-out in another worker
 * that landed between the two was written over. Every change of a row is now
 * made under a lock on its session id that all workers share.
 *
 * Every test runs on a private table and private locks, never the server's.
 */
#[RequiresPhpExtension('swoole')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class SwooleTableSessionHandlerLocksASessionIdTest extends TestCase
{
    private const MARKER = ['__retired__' => 1];
    private const SESSION = ['user_id' => 7, '__flash__' => []];
    /** What the handler waits for a lock before it goes on without it. */
    private const LOCK_WAIT_SECONDS = 0.1;

    /** @var array{class: class-string, values: array<string, mixed>} the handler's warning throttle, which a lock that does not come moves */
    private array $handlerState = ['class' => SwooleTableSessionHandler::class, 'values' => []];

    protected function setUp(): void
    {
        $this->handlerState = StaticState::snapshot(SwooleTableSessionHandler::class);
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->handlerState);
    }

    /**
     * A holder that is slow, or died: no change of a held session is made
     * without the lock, a retirement neither. CodeRabbit on semitexa-core#274:
     * a marker written beside a live holder was written over when it resumed.
     */
    #[Test]
    public function without_its_lock_no_change_of_a_held_session_is_made(): void
    {
        $locks = SwooleSessionTableHolder::createLocks(1);
        $handler = new SwooleTableSessionHandler($this->table(), $locks);
        $id = str_repeat('e', 32);
        $handler->write($id, self::SESSION, 600);
        self::assertTrue(self::free($locks[0]), 'held by this test, as by a worker that died');
        try {
            self::assertFalse($handler->writeIfHeld($id, ['user_id' => 8, '__flash__' => []], 600, self::MARKER), 'a save is dropped');
            self::assertSame(self::SESSION, $handler->read($id), 'and wrote nothing');
            self::assertFalse($handler->writeIfHeld($id, self::MARKER, 10, self::MARKER), 'a retirement neither');
            self::assertSame(self::SESSION, $handler->read($id), 'what the holder holds is what it read');
        } finally {
            $locks[0]->unlock();
        }
        self::assertTrue($handler->writeIfHeld($id, self::MARKER, 10, self::MARKER), 'its lock back, the retirement is made');
        self::assertSame(self::MARKER, $handler->read($id));
    }

    /** CodeRabbit on semitexa-core#274: a timed wait blocked every coroutine of the worker. */
    #[Test]
    public function a_wait_in_a_coroutine_lets_the_workers_other_coroutines_run(): void
    {
        $locks = SwooleSessionTableHolder::createLocks(1);
        $handler = new SwooleTableSessionHandler($this->table(), $locks);
        self::assertTrue(self::free($locks[0]), 'held by this test, as by a worker that died');
        $ticks = 0;
        $done = false;
        try {
            \Swoole\Coroutine\run(static function () use ($handler, &$ticks, &$done): void {
                \Swoole\Coroutine::create(static function () use ($handler, &$done): void {
                    $handler->destroy(str_repeat('a', 32));
                    $done = true;
                });
                \Swoole\Coroutine::create(static function () use (&$ticks, &$done): void {
                    while (!$done) {
                        $ticks++;
                        \Swoole\Coroutine::sleep(0.005);
                    }
                });
            });
        } finally {
            $locks[0]->unlock();
        }

        self::assertTrue($done);
        self::assertGreaterThanOrEqual(5, $ticks, 'the other coroutine ran while the destroy waited (about 20 ticks of 5 ms in 0.1 s)');
    }

    /**
     * Two processes on one id: one keeps saving the session it read (a
     * request in flight), the other signs out, again and again. Without the
     * lock about three sign-outs in four were written over here.
     */
    #[Test]
    public function a_sign_out_in_one_process_is_never_written_over_by_a_save_in_another(): void
    {
        $table = $this->table();
        $handler = new SwooleTableSessionHandler($table, SwooleSessionTableHolder::createLocks());
        $id = str_repeat('a', 32);
        $stop = new Atomic(0);
        $saved = new Atomic(0);
        $handler->write($id, self::SESSION, 600);

        $pid = pcntl_fork();
        if ($pid < 0) {
            self::fail('fork failed');
        }
        if ($pid === 0) {
            // The request in flight. Killed, not exited: this is a copy of
            // the test runner, and it must not finish the run a second time.
            try {
                $until = microtime(true) + 20;
                while ($stop->get() === 0 && microtime(true) < $until) {
                    if ($handler->writeIfHeld($id, self::SESSION, 600, self::MARKER)) {
                        $saved->add(1);
                    }
                }
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $writtenBack = 0;
        $retired = 0;
        try {
            $until = microtime(true) + 10;
            while ($saved->get() === 0 && microtime(true) < $until) {
                usleep(1000);
            }
            $savedBefore = $saved->get();
            for ($round = 0; $round < 20000; $round++) {
                $handler->write($id, self::SESSION, 600);
                if ($round % 256 === 0) {
                    // Room for the other process on a machine with one core.
                    usleep(200);
                }
                // A saver descheduled while it holds the stripe makes a
                // retirement wait past its lock and be refused: correct, and
                // tried again, as a sign-in answered "try again" is.
                // verify:accept-test-change a refused retirement is the fail-closed answer of a stripe whose holder is descheduled (dfa22ad), not a defect; the count of completed sign-outs and the no-write-back check stay
                $done = false;
                for ($try = 0; $try < 5 && !$done; $try++) {
                    $done = $handler->writeIfHeld($id, self::MARKER, 10, self::MARKER);
                }
                if (!$done) {
                    continue;
                }
                $retired++;
                for ($look = 0; $look < 20; $look++) {
                    if ($table->get($id, 'data') !== '{"__retired__":1}') {
                        $writtenBack++;
                        break;
                    }
                }
            }
        } finally {
            $stop->set(1);
            pcntl_waitpid($pid, $status);
        }

        self::assertGreaterThan(0, $savedBefore, 'the other process was saving before the first sign-out');
        self::assertGreaterThan($savedBefore, $saved->get(), 'and between the sign-outs');
        self::assertGreaterThan(10000, $retired, 'sign-outs went through while the other process saved');
        self::assertSame(0, $writtenBack, 'and every one stayed retired');
    }

    /** @return iterable<string, array{\Closure(SwooleTableSessionHandler, string): mixed}> */
    public static function changes(): iterable
    {
        yield 'a write' => [static fn (SwooleTableSessionHandler $h, string $id) => $h->write($id, self::SESSION, 600)];
        yield 'a held write' => [static fn (SwooleTableSessionHandler $h, string $id) => $h->writeIfHeld($id, self::SESSION, 600, self::MARKER)];
        yield 'a destroy' => [static fn (SwooleTableSessionHandler $h, string $id) => $h->destroy($id)];
    }

    /**
     * The lock is the one of the id's stripe, and it is asked for: held by
     * someone else, the call waits for it. (It then goes on without it: a
     * holder that died must not stop every worker for good.)
     *
     * @param \Closure(SwooleTableSessionHandler, string): mixed $change
     */
    #[Test]
    #[DataProvider('changes')]
    public function a_change_waits_for_the_lock_of_its_id(\Closure $change): void
    {
        $table = $this->table();
        $locks = SwooleSessionTableHolder::createLocks(8);
        $handler = new SwooleTableSessionHandler($table, $locks);
        $id = str_repeat('b', 32);
        $handler->write($id, self::SESSION, 600);
        $own = crc32($id) % 8;

        // Every other stripe held: none of them is asked for.
        foreach ($locks as $stripe => $lock) {
            if ($stripe !== $own) {
                self::assertTrue(self::free($lock));
            }
        }
        self::assertTrue(self::free($locks[$own]), 'the stripe of the id is free before the change');
        $locks[$own]->unlock();
        $change($handler, $id);
        self::assertTrue(self::free($locks[$own]), 'and is given back after it');

        // Now the id's own stripe is held (by this test, just above).
        $handler->write($id, self::SESSION, 600);
        $waited = microtime(true);
        $change($handler, $id);
        self::assertGreaterThanOrEqual(self::LOCK_WAIT_SECONDS * 0.9, microtime(true) - $waited, 'held elsewhere, it is waited for');
    }

    /** A lock that does not come is said, once a minute per worker, not on every write. */
    #[Test]
    public function a_lock_that_does_not_come_is_said_once_a_minute(): void
    {
        $logger = new class implements LoggerInterface {
            /** @var list<array{string, array<string, mixed>}> */
            public array $warnings = [];

            public function error(string $message, array $context = []): void {}
            public function critical(string $message, array $context = []): void {}
            public function warning(string $message, array $context = []): void { $this->warnings[] = [$message, $context]; }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
        $loggerState = StaticState::snapshot(StaticLoggerBridge::class);
        $handlerState = StaticState::snapshot(SwooleTableSessionHandler::class);
        StaticLoggerBridge::set($logger);
        (new \ReflectionProperty(SwooleTableSessionHandler::class, 'lockWarnedAt'))->setValue(null, 0);
        $locks = SwooleSessionTableHolder::createLocks(1);
        $handler = new SwooleTableSessionHandler($this->table(), $locks);
        try {
            self::assertTrue(self::free($locks[0]), 'held by this test, as by a worker that died');
            $handler->write(str_repeat('c', 32), self::SESSION, 600);
            $handler->write(str_repeat('d', 32), self::SESSION, 600);
            $locks[0]->unlock();
        } finally {
            StaticState::restore($handlerState);
            StaticState::restore($loggerState);
        }

        self::assertCount(1, $logger->warnings, 'two writes without the lock, one warning');
        self::assertStringContainsString('server:restart', $logger->warnings[0][0]);
        self::assertSame(0, $logger->warnings[0][1]['stripe'] ?? null);
        self::assertSame(self::SESSION, $handler->read(str_repeat('d', 32)), 'the write still went through');
    }

    #[Test]
    public function a_held_write_that_cannot_be_encoded_gives_no_lock_away(): void
    {
        $locks = SwooleSessionTableHolder::createLocks(1);
        $handler = new SwooleTableSessionHandler($this->table(), $locks);
        $id = str_repeat('c', 32);
        $handler->write($id, self::SESSION, 600);

        try {
            $handler->writeIfHeld($id, ['broken' => NAN], 600, self::MARKER);
            self::fail('NAN is not JSON');
        } catch (\JsonException) {
        }

        self::assertTrue(self::free($locks[0]), 'the lock is free');
        $locks[0]->unlock();
        self::assertSame(self::SESSION, $handler->read($id), 'and the session is as it was');
    }

    /**
     * CodeRabbit on semitexa-core#274: a reclaim whose lock did not come read
     * the expiry and deleted the row anyway, so a marker a slow holder was
     * writing could be taken away. Without its lock a row is left as it is.
     */
    #[Test]
    public function a_reclaim_without_the_lock_of_a_row_leaves_it(): void
    {
        $table = $this->table();
        $locks = SwooleSessionTableHolder::createLocks(2);
        $handler = new SwooleTableSessionHandler($table, $locks);
        // Every row on stripe 1, which a holder keeps; the new id on stripe 0.
        $rows = [];
        for ($i = 0; $i < 4000 && count($rows) < 2000; $i++) {
            $key = sprintf('%032x', $i);
            if (crc32($key) % 2 !== 1) {
                continue;
            }
            if (!@$table->set($key, ['data' => '{"__retired__":1}', 'expires_at' => time() + 1000 + $i])) {
                break;
            }
            $rows[] = $key;
        }
        $new = '';
        for ($i = 0xf0000; $new === ''; $i++) {
            $new = crc32(sprintf('%032x', $i)) % 2 === 0 ? sprintf('%032x', $i) : '';
        }
        self::assertNotSame([], $rows);
        self::assertTrue(self::free($locks[1]), 'held by this test, as by a slow holder');
        $thrown = null;
        try {
            $handler->write($new, self::SESSION, 600);
        } catch (SessionStoreFullException $e) {
            $thrown = $e;
        } finally {
            $locks[1]->unlock();
        }

        self::assertInstanceOf(SessionStoreFullException::class, $thrown, 'no row could be taken, so the table is full');
        self::assertSame(count($rows), $table->count(), 'no row was deleted');
        foreach ($rows as $key) {
            self::assertSame(['__retired__' => 1], $handler->read($key));
        }
    }

    /** A full table: the write evicts rows of other ids, each under its own lock, one at a time. */
    #[Test]
    public function a_write_that_reclaims_room_leaves_every_lock_free(): void
    {
        $table = $this->table();
        $locks = SwooleSessionTableHolder::createLocks(4);
        $handler = new SwooleTableSessionHandler($table, $locks);
        for ($i = 0; $i < 2000; $i++) {
            if (!@$table->set(sprintf('%032x', $i), ['data' => '{"n":1}', 'expires_at' => time() + 1000 + $i])) {
                break;
            }
        }
        $started = microtime(true);

        for ($i = 0; $i < 10; $i++) {
            $handler->write(sprintf('%032x', 0xf0000 + $i), self::SESSION, 3600);
        }

        // A lock taken twice (two at a time on one stripe) would wait once per
        // write, 10 waits; half of that leaves room for a stalled runner.
        self::assertLessThan(5 * self::LOCK_WAIT_SECONDS, microtime(true) - $started, 'no lock was waited for');
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(self::SESSION, $handler->read(sprintf('%032x', 0xf0000 + $i)));
        }
        foreach ($locks as $lock) {
            self::assertTrue(self::free($lock));
        }
    }

    #[Test]
    public function an_expired_row_is_deleted_by_a_read_under_its_lock(): void
    {
        $table = $this->table();
        $locks = SwooleSessionTableHolder::createLocks(1);
        $handler = new SwooleTableSessionHandler($table, $locks);
        $id = str_repeat('d', 32);
        $table->set($id, ['data' => '{"user_id":7}', 'expires_at' => time() - 5]);

        self::assertSame([], $handler->read($id));

        self::assertFalse($table->exists($id));
        self::assertTrue(self::free($locks[0]), 'the lock is free');
    }

    /** No locks at all (a private table given none; the CLI): the handler works as it did. */
    #[Test]
    public function without_locks_every_call_still_works(): void
    {
        $handler = new SwooleTableSessionHandler($this->table());
        $id = str_repeat('e', 32);

        $handler->write($id, self::SESSION, 600);
        self::assertTrue($handler->writeIfHeld($id, ['user_id' => 8, '__flash__' => []], 600, self::MARKER));
        self::assertSame(['user_id' => 8, '__flash__' => []], $handler->read($id));
        self::assertTrue($handler->writeIfHeld($id, self::MARKER, 10, self::MARKER));
        self::assertFalse($handler->writeIfHeld($id, self::SESSION, 600, self::MARKER));
        $handler->destroy($id);
        self::assertSame([], $handler->read($id));
    }

    #[Test]
    public function the_servers_table_gets_its_locks_with_it(): void
    {
        $table = (new \ReflectionProperty(SwooleSessionTableHolder::class, 'table'));
        $locks = (new \ReflectionProperty(SwooleSessionTableHolder::class, 'locks'));
        $before = [$table->getValue(), $locks->getValue()];
        try {
            SwooleSessionTableHolder::setTable($this->table());

            $shared = SwooleSessionTableHolder::getLocks();
            self::assertCount(SwooleSessionTableHolder::LOCK_STRIPES, $shared);
            self::assertContainsOnlyInstancesOf(Lock::class, $shared);

            // The handler without a table of its own uses them.
            $id = str_repeat('f', 32);
            $handler = new SwooleTableSessionHandler();
            $handler->write($id, self::SESSION, 600);
            $own = $shared[crc32($id) % count($shared)];
            self::assertTrue(self::free($own));
            $waited = microtime(true);
            self::assertFalse($handler->writeIfHeld($id, ['user_id' => 9, '__flash__' => []], 600, self::MARKER), 'its stripe held, a held write is dropped');
            self::assertGreaterThanOrEqual(self::LOCK_WAIT_SECONDS * 0.9, microtime(true) - $waited);
            self::assertSame(self::SESSION, $handler->read($id));
            $own->unlock();
        } finally {
            $table->setValue(null, $before[0]);
            $locks->setValue(null, $before[1]);
        }
    }

    /** Takes $lock when nobody holds it, without waiting. */
    private static function free(Lock $lock): bool
    {
        // @phpstan-ignore arguments.count (Swoole 6.1 gave lock() its operation; the stubs are 5.1's)
        return $lock->lock(LOCK_EX | LOCK_NB);
    }

    private function table(): Table
    {
        $table = new Table(64);
        $table->column('data', Table::TYPE_STRING, 256);
        $table->column('expires_at', Table::TYPE_INT, 8);
        $table->create();

        return $table;
    }
}
