<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Security\AppSecretDoctorCheck;

final class AppSecretDoctorCheckTest extends TestCase
{
    #[Test]
    public function a_server_without_a_secret_fails_before_its_first_signed_link_does(): void
    {
        self::assertSame('fail', AppSecretDoctorCheck::verdict('prod', '')->status->value);
        self::assertSame('fail', AppSecretDoctorCheck::verdict('staging', '')->status->value);
        self::assertSame('pass', AppSecretDoctorCheck::verdict('dev', '')->status->value, 'dev has a stand-in');
        self::assertSame('warn', AppSecretDoctorCheck::verdict('prod', 'changeme')->status->value);
        self::assertSame('pass', AppSecretDoctorCheck::verdict('prod', str_repeat('a1', 32))->status->value);
        self::assertSame('fail', AppSecretDoctorCheck::verdict(strtolower(' PROD '), '')->status->value);
    }
}
