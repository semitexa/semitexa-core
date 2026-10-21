<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueStopSignals;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\StoppableQueueTransportInterface;

/**
 * `docker stop` sent SIGTERM and the worker died in the middle of a job. A
 * real SIGTERM, sent to this process from inside a job, lets that job finish
 * and ends the loop before the next one.
 */
final class QueueStopSignalsTest extends TestCase
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
    public function sigterm_finishes_the_job_in_hand_and_takes_no_next(): void
    {
        $transport = new class implements StoppableQueueTransportInterface {
            /** @var list<string> */
            public array $queued = ['first', 'second', 'third'];
            private bool $running = true;

            public function publish(string $queueName, string $payload): void
            {
                $this->queued[] = $payload;
            }

            public function consume(string $queueName, callable $callback): void
            {
                while ($this->running && $this->queued !== []) {
                    $callback(array_shift($this->queued));
                }
            }

            public function stop(): void
            {
                $this->running = false;
            }
        };
        $said = [];
        self::assertTrue(QueueStopSignals::install($transport, static function (string $m) use (&$said): void { $said[] = $m; }));

        $done = [];
        $transport->consume('q', static function (string $payload) use (&$done): void {
            if ($payload === 'first') {
                posix_kill(posix_getpid(), SIGTERM);
                usleep(10_000); // the signal lands while the job still runs
            }
            $done[] = $payload;
        });

        self::assertSame(['first'], $done, 'the job in hand ran to its end, and no other');
        self::assertSame(['second', 'third'], $transport->queued, 'the rest wait for the next worker');
        self::assertCount(1, $said);
        self::assertStringContainsString('stopping after the message in hand', $said[0]);
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM), 'a second signal ends the worker at once');
    }

    #[Test]
    public function a_transport_that_cannot_stop_gets_no_handler(): void
    {
        $transport = new class implements QueueTransportInterface {
            public function publish(string $queueName, string $payload): void {}
            public function consume(string $queueName, callable $callback): void {}
        };

        self::assertFalse(QueueStopSignals::install($transport), 'a signal ends such a worker as before');
    }
}
