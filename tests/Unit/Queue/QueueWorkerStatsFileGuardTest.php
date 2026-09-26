<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Queue\QueueWorker;

/**
 * updateStats() reads the stats file with file_get_contents() and fed the
 * result straight to json_decode(). Every failure path in the worker calls
 * updateStats('failed'), so a stats file that briefly cannot be read (deleted
 * externally, a permissions hiccup, a race with another worker) turned
 * `file_get_contents()`'s `false` into an uncaught TypeError inside the very
 * code recording *that* failure — instead of falling back to a fresh counter.
 */
final class QueueWorkerStatsFileGuardTest extends TestCase
{
    #[Test]
    public function update_stats_falls_back_to_a_fresh_counter_when_the_stats_file_cannot_be_read(): void
    {
        $missingFile = sys_get_temp_dir() . '/semitexa-queue-stats-' . bin2hex(random_bytes(8)) . '.json';
        self::assertFileDoesNotExist($missingFile);

        $worker = (new \ReflectionClass(QueueWorker::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(QueueWorker::class, 'statsFile'))->setValue($worker, $missingFile);

        (new ReflectionMethod(QueueWorker::class, 'updateStats'))->invoke($worker, 'failed');

        self::assertFileExists($missingFile, 'updateStats() must still persist a stats file after the failed read');
        $stats = json_decode((string) file_get_contents($missingFile), true);
        self::assertIsArray($stats);
        self::assertSame(1, $stats['failed'] ?? null);

        @unlink($missingFile);
    }
}
