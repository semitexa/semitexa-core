<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\QueueStatsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueStatsReport;
use Semitexa\Core\Queue\QueueStatsStoreInterface;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * queue:stats --json used to exit 0 with an `error` in the JSON, so a monitor
 * reading it was never told; and a counter store that was down hid the live
 * depth behind its own error.
 */
final class QueueStatsCommandTest extends TestCase
{
    private const TRANSPORT = 'queue-stats-command-test';

    protected function setUp(): void
    {
        putenv('EVENTS_TRANSPORT=' . self::TRANSPORT);
        QueueTransportRegistry::register(self::TRANSPORT, new class implements QueueTransportFactoryInterface {
            public function create(): QueueTransportInterface
            {
                // Not an inspector: depth is unknown, never an error.
                return new class implements QueueTransportInterface {
                    public function publish(string $queueName, string $payload): void {}
                    public function consume(string $queueName, callable $callback): void {}
                };
            }
        });
        self::useStore(null);
    }

    protected function tearDown(): void
    {
        putenv('EVENTS_TRANSPORT');
        QueueStatsReport::forgetStore();
        QueueTransportRegistry::reset();
    }

    #[Test]
    public function a_readable_report_exits_zero_with_counts(): void
    {
        $tester = $this->stats(['--json' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $json = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('off', $json['store']);
        $row = $json['queues'][0];
        self::assertSame(self::TRANSPORT, $row['transport']);
        self::assertSame(0, $row['processed']);
        self::assertNull($row['error']);
        self::assertNull($row['counters_error']);
    }

    #[Test]
    public function counters_that_cannot_be_read_fail_the_json_form_too_and_are_null_not_zero(): void
    {
        self::useStore(new class implements QueueStatsStoreInterface {
            public function add(string $queue, int $minute, array $deltas): void {}
            public function sum(string $queue, int $fromMinute, int $toMinute): array
            {
                throw new \RuntimeException("redis gone \xC3\x28");
            }
            public function describe(): string
            {
                return 'redis';
            }
        });

        $json = $this->stats(['--json' => true]);
        self::assertSame(Command::FAILURE, $json->getStatusCode());
        $row = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['queues'][0];
        self::assertNull($row['processed']);
        self::assertStringStartsWith('redis gone', $row['counters_error'], 'invalid UTF-8 in a message does not blank the output');
        self::assertNull($row['error']);

        $table = $this->stats([]);
        self::assertSame(Command::FAILURE, $table->getStatusCode());
        self::assertStringContainsString('counters (redis): redis gone', $table->getDisplay());
    }

    /** @param array<string, mixed> $options */
    private function stats(array $options): CommandTester
    {
        $command = new QueueStatsCommand();
        (new \ReflectionProperty($command, 'inspection'))->setValue($command, ContainerFactory::get()->get(QueueInspection::class));
        $tester = new CommandTester($command);
        $tester->execute($options + ['--queue' => 'events']);

        return $tester;
    }

    private static function useStore(?QueueStatsStoreInterface $store): void
    {
        (new \ReflectionProperty(QueueStatsReport::class, 'store'))->setValue(null, $store);
        (new \ReflectionProperty(QueueStatsReport::class, 'storeResolved'))->setValue(null, true);
    }
}
