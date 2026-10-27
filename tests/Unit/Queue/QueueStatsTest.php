<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\FileQueueStatsStore;
use Semitexa\Core\Queue\QueueStatsRecorder;
use Semitexa\Core\Queue\QueueStatsStoreInterface;
use Semitexa\Core\Queue\QueueStatsWindow;

final class QueueStatsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-qstats-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** add() returned quietly, so the recorder never said the counts were lost. */
    #[Test]
    public function a_directory_that_cannot_be_made_throws(): void
    {
        file_put_contents($this->dir, 'a file where the directory should go');
        try {
            (new FileQueueStatsStore($this->dir . '/stats'))->add('mail', 100, ['processed' => 1]);
            self::fail('add() returned');
        } catch (\RuntimeException $e) {
            self::assertSame("Cannot create {$this->dir}/stats", $e->getMessage());
        } finally {
            unlink($this->dir);
        }
    }

    /** A day file that could not be decoded was written over with one minute's counts. */
    #[Test]
    public function an_unreadable_day_is_moved_aside_not_written_over(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/mail.0.json', '{"100":{"processed":7}');
        $store = new FileQueueStatsStore($this->dir);

        try {
            $store->add('mail', 100, ['processed' => 1]);
            self::fail('add() returned');
        } catch (\RuntimeException $e) {
            self::assertSame("{$this->dir}/mail.0.json could not be read; moved aside to {$this->dir}/mail.0.json.unreadable", $e->getMessage());
        }
        self::assertSame('{"100":{"processed":7}', file_get_contents($this->dir . '/mail.0.json.unreadable'));

        $store->add('mail', 100, ['processed' => 1]);
        self::assertSame(['processed' => 1], $store->sum('mail', 100, 100), 'the next job starts the day again');
    }

    #[Test]
    public function every_finished_job_is_written_at_once_into_its_minute(): void
    {
        // A first version buffered: a worker blocked in its transport between
        // jobs and stopped by a signal never wrote four of five jobs.
        $now = 6000.0;
        $store = new FileQueueStatsStore($this->dir);
        $recorder = new QueueStatsRecorder($store, static function () use (&$now): float {
            return $now;
        });

        $recorder->record('mail', 'processed', false, 40, 120);
        $recorder->record('mail', 'dead_lettered', false, 900, 30);
        $recorder->record('mail', 'processed', true, null, 5);
        $now = 6060.0;
        $recorder->record('mail', 'processed', false, 20, 10);

        $first = $store->sum('mail', 100, 100);
        self::assertSame(1, $first['processed']);
        self::assertSame(1, $first['dead_lettered']);
        self::assertSame(1, $first['retried'], 'a retried attempt counts as a retry, not as done');
        self::assertSame(2, $first['wait_count'], 'an attempt without publishedAt has no wait');
        self::assertSame(3, $first['run_count']);
        self::assertSame(1, $store->sum('mail', 101, 101)['processed'] ?? null, 'the next minute has its own counters');
        self::assertSame(2, $store->sum('mail', 100, 101)['processed']);
    }

    #[Test]
    public function percentiles_are_bucket_bounds_and_the_window_reads_a_rate(): void
    {
        $window = new QueueStatsWindow([
            'processed' => 90, 'dead_lettered' => 6, 'retried' => 4,
            'run_count' => 100, 'run_ms_sum' => 30_000,
            'run_le_50' => 60, 'run_le_250' => 30, 'run_le_5000' => 9, 'run_le_inf' => 1,
        ], 10);

        self::assertSame(10.0, $window->perMinute());
        self::assertSame(300, $window->averageMs('run'));
        self::assertSame(50, $window->percentileMs('run', 0.5));
        self::assertSame(5000, $window->percentileMs('run', 0.95));
        self::assertSame('inf', $window->percentileMs('run', 1.0));
        self::assertNull($window->percentileMs('wait', 0.5), 'nothing waited');
        self::assertSame(['10', '10', '250', 'inf'], [QueueStatsRecorder::bucket(0), QueueStatsRecorder::bucket(10), QueueStatsRecorder::bucket(251 - 1), QueueStatsRecorder::bucket(30_001)]);
    }

    #[Test]
    public function a_store_that_fails_loses_the_counts_not_the_job(): void
    {
        $broken = new class () implements QueueStatsStoreInterface {
            public function add(string $queue, int $minute, array $deltas): void { throw new \RuntimeException('redis is away'); }
            public function sum(string $queue, int $fromMinute, int $toMinute): array { return []; }
            public function describe(): string { return 'broken'; }
        };

        (new QueueStatsRecorder($broken))->record('mail', 'processed', false, 1, 1);

        $this->addToAssertionCount(1);
    }
}
