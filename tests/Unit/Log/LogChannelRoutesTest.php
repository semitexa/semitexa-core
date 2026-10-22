<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LogChannelRoutes;

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
}
