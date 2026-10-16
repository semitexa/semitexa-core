<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\WorkerMemoryWatchdog;

/**
 * When a worker is recycled for its memory.
 *
 * The portal outage of August 2026: workers wedged on a blocking Redis read
 * and died at the 128M limit. SWOOLE_MAX_REQUEST could not help — it counts
 * finished requests, and a wedged worker stops finishing them — so a worker
 * has to watch its own memory and leave, through the drain, before PHP kills
 * it mid-request.
 */
final class WorkerMemoryWatchdogTest extends TestCase
{
    /** @return iterable<string, array{string, int|null}> */
    public static function limits(): iterable
    {
        yield 'megabytes' => ['512M', 512 * 1024 * 1024];
        yield 'lower-case unit' => ['256m', 256 * 1024 * 1024];
        yield 'gigabytes' => ['1G', 1024 * 1024 * 1024];
        yield 'kilobytes' => ['131072K', 128 * 1024 * 1024];
        yield 'plain bytes' => ['134217728', 128 * 1024 * 1024];
        yield 'spaces around' => [' 512M ', 512 * 1024 * 1024];
        yield 'unlimited' => ['-1', null];
        yield 'empty' => ['', null];
        yield 'nonsense' => ['lots', null];
        yield 'zero' => ['0', null];
    }

    #[Test]
    #[DataProvider('limits')]
    public function it_reads_memory_limit_as_php_does(string $ini, ?int $bytes): void
    {
        self::assertSame($bytes, WorkerMemoryWatchdog::parseBytes($ini));
    }

    #[Test]
    public function the_threshold_is_the_ratio_of_the_limit(): void
    {
        $watchdog = WorkerMemoryWatchdog::fromLimit('512M', 0.8);

        self::assertNotNull($watchdog);
        self::assertSame(429496729, $watchdog->thresholdBytes);
        self::assertFalse($watchdog->isOver(429496729));
        self::assertTrue($watchdog->isOver(429496730));
    }

    /** @return iterable<string, array{string, float}> */
    public static function disabled(): iterable
    {
        yield 'no limit to measure against' => ['-1', 0.8];
        yield 'turned off' => ['512M', 0.0];
        yield 'negative ratio' => ['512M', -0.5];
        // At or past the limit PHP has already killed the worker.
        yield 'ratio at the limit' => ['512M', 1.0];
        yield 'ratio past the limit' => ['512M', 1.2];
    }

    #[Test]
    #[DataProvider('disabled')]
    public function it_stands_down_when_there_is_nothing_sensible_to_watch(string $ini, float $ratio): void
    {
        self::assertNull(WorkerMemoryWatchdog::fromLimit($ini, $ratio));
    }
}
