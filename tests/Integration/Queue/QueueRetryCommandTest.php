<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\QueueRetryCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Queue\DeadLetter;
use Semitexa\Core\Queue\QueueInspection;
use Semitexa\Core\Queue\QueueInspectorInterface;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Tests\Support\StaticState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * queue:retry --all takes at most 1000 dead letters; with more it said
 * "Queued again 1000" and exited 0, which read as a drained queue.
 */
final class QueueRetryCommandTest extends TestCase
{
    private const TRANSPORT = 'queue-retry-command-test';

    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $registry;

    protected function setUp(): void
    {
        $this->registry = StaticState::snapshot(QueueTransportRegistry::class);
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->registry);
    }

    #[Test]
    public function a_full_batch_says_more_may_be_left(): void
    {
        $display = $this->retryAll(1500);

        self::assertSame(
            "Queued again 1000 dead letter(s) of events.\n"
            . "--all takes the newest 1000 dead letters at a time; more may be left: run it again.\n",
            $display,
        );
    }

    #[Test]
    public function a_batch_under_the_limit_says_only_what_it_did(): void
    {
        self::assertSame("Queued again 3 dead letter(s) of events.\n", $this->retryAll(3));
    }

    private function retryAll(int $letters): string
    {
        QueueTransportRegistry::register(self::TRANSPORT, new class ($letters) implements QueueTransportFactoryInterface {
            public function __construct(private readonly int $letters) {}

            public function create(): QueueTransportInterface
            {
                return new class ($this->letters) implements QueueTransportInterface, QueueInspectorInterface {
                    public function __construct(private readonly int $letters) {}
                    public function publish(string $queueName, string $payload): void {}
                    public function consume(string $queueName, callable $callback): void {}
                    public function depth(string $queue): int { return 0; }
                    public function oldestAgeSeconds(string $queue): ?int { return null; }
                    public function deadLetters(string $queue, int $limit = 20): array
                    {
                        return array_map(
                            static fn (int $i): DeadLetter => new DeadLetter((string) $i, $queue, 'App\\Job', 3, 'failed', null, '{}', true),
                            range(1, min($limit, $this->letters)),
                        );
                    }
                    public function retryDeadLetter(string $queue, string $id): bool { return true; }
                    public function forgetDeadLetter(string $queue, string $id): bool { return true; }
                };
            }
        });

        $command = new QueueRetryCommand();
        (new \ReflectionProperty($command, 'inspection'))->setValue($command, ContainerFactory::get()->get(QueueInspection::class));
        $tester = new CommandTester($command);
        $tester->execute(['--queue' => 'events', '--transport' => self::TRANSPORT, '--all' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        return $tester->getDisplay();
    }
}
