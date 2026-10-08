<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\ServerConfigurator;

/**
 * SWOOLE_PACKAGE_MAX_LENGTH reaches Swoole as package_max_length — the
 * largest request it accepts, and so the ceiling of every upload. Before it
 * existed the limit was Swoole's built-in 2 MB with no way to raise it, and a
 * bigger upload was dropped as a network error.
 */
final class ServerPackageMaxLengthTest extends TestCase
{
    private function env(?int $packageMaxLength = null): Environment
    {
        $args = [
            'appEnv' => 'test', 'appDebug' => false, 'appName' => 'Semitexa', 'appHost' => 'localhost', 'appPort' => 8000,
            'swoolePort' => 9502, 'swooleSsePort' => 9503, 'swooleHost' => '0.0.0.0', 'swooleWorkerNum' => 1,
            'swooleMaxRequest' => 1, 'swooleMaxCoroutine' => 1, 'swooleLogFile' => 'var/log/swoole.log', 'swooleLogLevel' => 1,
            'swooleSessionTableSize' => 1, 'swooleSessionMaxBytes' => 1, 'swooleSseWorkerTableSize' => 1,
            'swooleSseDeliverTableSize' => 1, 'swooleSsePayloadMaxBytes' => 1,
            'corsAllowOrigin' => '*', 'corsAllowMethods' => 'GET', 'corsAllowHeaders' => 'Content-Type', 'corsAllowCredentials' => false,
        ];
        if ($packageMaxLength !== null) {
            $args['swoolePackageMaxLength'] = $packageMaxLength;
        }

        return new Environment(...$args);
    }

    #[Test]
    public function the_server_takes_a_phone_photo_unless_told_otherwise(): void
    {
        self::assertSame(33_554_432, (new ServerConfigurator($this->env()))->getServerOptions()['package_max_length']);
        self::assertSame(8_388_608, (new ServerConfigurator($this->env(8_388_608)))->getServerOptions()['package_max_length']);
        self::assertSame('8388608', $this->env(8_388_608)->get('SWOOLE_PACKAGE_MAX_LENGTH'));
    }

    #[Test]
    public function a_valid_value_parses(): void
    {
        self::assertSame(65_536, Environment::parsePackageMaxLength('65536'));
        self::assertSame(52_428_800, Environment::parsePackageMaxLength('52428800'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'too small to post a form' => ['65535'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'a unit suffix' => ['8M'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function an_unusable_value_is_refused_at_boot(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SWOOLE_PACKAGE_MAX_LENGTH');
        Environment::parsePackageMaxLength($value);
    }
}
