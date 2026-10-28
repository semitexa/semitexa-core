<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\StopSignals;

/**
 * webhook:work and scheduler:work died mid-delivery and mid-run on docker
 * stop. The same handler queue:work uses asks any loop to stop after the work
 * in hand.
 */
final class StopSignalsTest extends TestCase
{
    protected function tearDown(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals(false);
        }
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function sigterm_asks_the_loop_to_stop_and_a_second_one_would_kill(): void
    {
        $running = true;
        $said = [];
        self::assertTrue(StopSignals::install(
            static function () use (&$running): void { $running = false; },
            static function (string $m) use (&$said): void { $said[] = $m; },
            'the run in hand',
        ));

        $done = 0;
        while ($running) {
            posix_kill(posix_getpid(), SIGTERM);
            usleep(10_000);
            $done++;
        }

        self::assertSame(1, $done, 'the work in hand finished, and no more');
        self::assertSame(['Signal 15: stopping after the run in hand (again to stop at once).'], $said);
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM));
    }
}
