<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LogChannelRoutes;
use Semitexa\Core\Log\LogRoute;
use Semitexa\Core\Support\ProjectRoot;

final class LogChannelRoutesTest extends TestCase
{
    /**
     * Only a leading ./ was taken off: `var/log/./swoole.log` passed as a file
     * of its own, and its rotation renamed the server's log from under it.
     */
    #[Test]
    public function every_dot_segment_is_taken_out_of_a_path(): void
    {
        self::assertSame('var/log/swoole.log', LogChannelRoutes::normalize('var/log/./swoole.log'));
        self::assertSame('var/log/a.log', LogChannelRoutes::normalize('././var/log/./././a.log'));
        self::assertSame('var/log/a./b.log', LogChannelRoutes::normalize('var/log/a./b.log'), 'a dot inside a name stays');
    }

    #[Test]
    public function a_reserved_file_spelled_with_dot_segments_is_refused(): void
    {
        $rejected = [];
        $routes = LogChannelRoutes::fromEnv('audit=var/log/./swoole.log,sql=var/log/./app.log', 'var/log/app.log', $rejected);

        self::assertArrayNotHasKey('audit', $routes);
        self::assertArrayNotHasKey('sql', $routes);
        self::assertCount(2, $rejected);
    }

    /** Only the default path was reserved: a moved Swoole log took channel records and their rotation. */
    #[Test]
    public function the_configured_swoole_log_is_refused_wherever_it_is_put(): void
    {
        $previous = getenv('SWOOLE_LOG_FILE');
        try {
            putenv('SWOOLE_LOG_FILE=var/log/server.log');
            $rejected = [];
            $routes = LogChannelRoutes::fromEnv('audit=var/log/server.log,sql=var/log/sql.log', 'var/log/app.log', $rejected);
            self::assertSame(['audit=var/log/server.log'], $rejected);
            self::assertEquals(['sql' => new LogRoute('var/log/sql.log', false)], $routes);

            putenv('SWOOLE_LOG_FILE=' . ProjectRoot::get() . '/var/log/server.log');
            $rejected = [];
            LogChannelRoutes::fromEnv('audit=var/log/./server.log', 'var/log/app.log', $rejected);
            self::assertSame(['audit=var/log/./server.log'], $rejected, 'an absolute path inside the project is the same file');

            // review: /var/log/server.log is not the project's var/log/server.log.
            putenv('SWOOLE_LOG_FILE=/var/log/server.log');
            $rejected = [];
            $routes = LogChannelRoutes::fromEnv('audit=var/log/server.log', 'var/log/app.log', $rejected);
            self::assertSame([], $rejected, 'a Swoole log outside the project reserves nothing');
            self::assertEquals(['audit' => new LogRoute('var/log/server.log', false)], $routes);
        } finally {
            putenv($previous === false ? 'SWOOLE_LOG_FILE' : 'SWOOLE_LOG_FILE=' . $previous);
        }
    }

    /** The paths were compared as text: a link outside the project into its var/log named the same file twice. */
    #[Test]
    public function the_swoole_log_reached_through_a_link_is_refused(): void
    {
        $previous = getenv('SWOOLE_LOG_FILE');
        $logDir = ProjectRoot::get() . '/var/log';
        $link = sys_get_temp_dir() . '/log-link-' . bin2hex(random_bytes(4));
        is_dir($logDir) || mkdir($logDir, 0775, true);
        symlink($logDir, $link);
        try {
            putenv('SWOOLE_LOG_FILE=' . $link . '/server.log');
            $rejected = [];
            $routes = LogChannelRoutes::fromEnv('audit=var/log/server.log,sql=var/log/sql.log', 'var/log/app.log', $rejected);
            self::assertSame(['audit=var/log/server.log'], $rejected, 'the file does not exist yet, its directory is the same');
            self::assertEquals(['sql' => new LogRoute('var/log/sql.log', false)], $routes);
        } finally {
            unlink($link);
            putenv($previous === false ? 'SWOOLE_LOG_FILE' : 'SWOOLE_LOG_FILE=' . $previous);
        }
    }
}
