<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;
use Semitexa\Core\Server\ServerConfigurator;
use Semitexa\Core\Support\ProjectRoot;

final class ServerConfiguratorLogTest extends TestCase
{
    private function env(string $logFile, string $rotation = 'daily', int $retentionDays = 14): Environment
    {
        return new Environment(
            appEnv: 'test',
            appDebug: false,
            appName: 'Semitexa',
            appHost: 'localhost',
            appPort: 8000,
            swoolePort: 9502,
            swooleSsePort: 9503,
            swooleHost: '0.0.0.0',
            swooleWorkerNum: 1,
            swooleMaxRequest: 1,
            swooleMaxCoroutine: 1,
            swooleLogFile: $logFile,
            swooleLogLevel: 1,
            swooleSessionTableSize: 1,
            swooleSessionMaxBytes: 1,
            swooleSseWorkerTableSize: 1,
            swooleSseDeliverTableSize: 1,
            swooleSsePayloadMaxBytes: 1,
            corsAllowOrigin: '*',
            corsAllowMethods: 'GET',
            corsAllowHeaders: 'Content-Type',
            corsAllowCredentials: false,
            swooleLogRotation: $rotation,
            swooleLogRetentionDays: $retentionDays,
        );
    }

    /**
     * Review finding on PR #90, rated major: retention pruned the absolute path while
     * Swoole was handed the raw relative one. Whenever the process working directory
     * was not the project root, rotation wrote to one directory and the sweep scanned
     * another — so nothing was ever pruned and the growth this change exists to stop
     * carried on silently.
     */
    #[Test]
    public function swoole_is_configured_with_the_same_path_retention_scans(): void
    {
        $config = new ServerConfigurator($this->env('var/log/swoole.log'));

        $logFileOption = $config->getServerOptions()['log_file'];

        self::assertSame($config->logFilePath(), $logFileOption);
        self::assertStringStartsWith(ProjectRoot::get(), (string) $logFileOption);
    }

    #[Test]
    public function an_absolute_configured_path_is_left_alone(): void
    {
        $config = new ServerConfigurator($this->env('/var/log/semitexa/swoole.log'));

        self::assertSame('/var/log/semitexa/swoole.log', $config->logFilePath());
    }

    #[Test]
    public function the_rotation_setting_reaches_swoole_as_its_integer(): void
    {
        self::assertSame(2, $this->configFor('daily')->getServerOptions()['log_rotation']);
        self::assertSame(0, $this->configFor('single')->getServerOptions()['log_rotation']);
    }

    private function configFor(string $rotation): ServerConfigurator
    {
        return new ServerConfigurator($this->env('var/log/swoole.log', $rotation));
    }
}
