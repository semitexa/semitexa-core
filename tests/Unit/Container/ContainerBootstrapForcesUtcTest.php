<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;

/**
 * The whole stack assumes DateTimeImmutable defaults to UTC — ORM DATETIME
 * round-trips, scheduler claim/lease `now` strings, calendar windows. That
 * assumption is enforced, not merely documented: ContainerFactory::create()
 * (the shared server + CLI bootstrap) pins the default timezone to UTC, so a
 * deploy on a non-UTC php.ini can't silently shift every stored instant by the
 * machine offset.
 */
final class ContainerBootstrapForcesUtcTest extends TestCase
{
    private string $tzBefore = 'UTC';

    protected function setUp(): void
    {
        $this->tzBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tzBefore);
    }

    #[Test]
    public function create_overrides_a_non_utc_ambient_timezone(): void
    {
        // Simulate a box whose php.ini date.timezone is non-UTC.
        date_default_timezone_set('America/New_York');

        // The UTC set is the first statement of create(), before the memoize
        // guard, so this is cheap when the container is already built.
        ContainerFactory::create();

        self::assertSame('UTC', date_default_timezone_get());
        self::assertSame('UTC', (new \DateTimeImmutable())->getTimezone()->getName());
    }

    #[Test]
    public function the_test_process_itself_is_utc(): void
    {
        // The phpunit bootstrap enforces UTC too, since many unit tests build
        // DateTimeImmutable without a container — this guards that line.
        self::assertSame('UTC', date_default_timezone_get());
    }
}
