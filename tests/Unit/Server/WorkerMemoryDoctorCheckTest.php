<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\WorkerMemoryDoctorCheck;

/**
 * What system:doctor says about a worker's memory.
 *
 * Read from PHP_MEMORY_LIMIT, the value docker-compose.yml hands the server
 * as `-d memory_limit`, never from the CLI's own ini: on semitexa.com
 * (2026-10-16) `php -r 'echo ini_get("memory_limit");'` answered 128M while
 * every worker ran with 256M — a check reading its own process would have
 * reported the wrong number with full confidence.
 */
final class WorkerMemoryDoctorCheckTest extends TestCase
{
    #[Test]
    public function a_sane_limit_with_the_watchdog_on_passes_and_says_where_it_recycles(): void
    {
        $result = WorkerMemoryDoctorCheck::verdict('512M', 0.8);

        self::assertSame('pass', $result->status->value);
        self::assertStringContainsString('512M', $result->message);
        self::assertStringContainsString('409.6 MB', $result->message);
    }

    #[Test]
    public function a_limit_under_the_floor_warns(): void
    {
        $result = WorkerMemoryDoctorCheck::verdict('128M', 0.8);

        self::assertSame('warn', $result->status->value);
        self::assertStringContainsString('256M', (string) $result->hint);
    }

    #[Test]
    public function an_unset_limit_warns_that_the_compose_default_decides(): void
    {
        $result = WorkerMemoryDoctorCheck::verdict(null, 0.8);

        self::assertSame('warn', $result->status->value);
        self::assertStringContainsString('PHP_MEMORY_LIMIT', $result->message);
    }

    #[Test]
    public function no_limit_leaves_the_watchdog_nothing_to_measure(): void
    {
        $result = WorkerMemoryDoctorCheck::verdict('-1', 0.8);

        self::assertSame('warn', $result->status->value);
        self::assertStringContainsString('watchdog', $result->message);
    }

    #[Test]
    public function a_watchdog_turned_off_warns(): void
    {
        $result = WorkerMemoryDoctorCheck::verdict('512M', 0.0);

        self::assertSame('warn', $result->status->value);
        self::assertStringContainsString('SWOOLE_MEMORY_WATCHDOG_RATIO', $result->message);
    }
}
