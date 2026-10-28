<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\RateLimit\RateLimitStoreChoice;
use Semitexa\Core\RateLimit\RateLimitStoreDoctorCheck;

final class RateLimitStoreDoctorCheckTest extends TestCase
{
    #[Test]
    public function a_store_that_cannot_hold_the_limit_for_the_whole_server_warns(): void
    {
        self::assertSame('warn', RateLimitStoreDoctorCheck::verdict('memory', '', '4')->status->value);
        self::assertSame('warn', RateLimitStoreDoctorCheck::verdict('redis', '', '4')->status->value);
        self::assertSame('warn', RateLimitStoreDoctorCheck::verdict('valkey', '', '4')->status->value);
    }

    #[Test]
    public function a_shared_store_passes(): void
    {
        self::assertSame('pass', RateLimitStoreDoctorCheck::verdict('auto', '', '4')->status->value);
        self::assertSame('pass', RateLimitStoreDoctorCheck::verdict('', 'redis', '4')->status->value);
        self::assertSame('pass', RateLimitStoreDoctorCheck::verdict('table', 'redis', '4')->status->value);
        self::assertSame('pass', RateLimitStoreDoctorCheck::verdict('memory', '', '1')->status->value);
    }

    #[Test]
    public function auto_wants_both_and_falls_back_from_redis_to_the_table(): void
    {
        self::assertTrue(RateLimitStoreChoice::Auto->wantsRedis());
        self::assertTrue(RateLimitStoreChoice::Auto->wantsTable());
        self::assertFalse(RateLimitStoreChoice::Redis->wantsTable());
        self::assertFalse(RateLimitStoreChoice::Memory->wantsTable());
        self::assertFalse(RateLimitStoreChoice::Table->wantsRedis());
    }
}
