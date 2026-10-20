<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Semitexa\Core\Application\Console\Command\WorkerProcessesCommand;
use Semitexa\Core\Contract\WorkerProcessSourceInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Queue\WorkerProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * worker:processes (tk-worker-runs-mail-work): what the scaffold's worker
 * container runs. Only queue:work ran there, so queued mail, media variants,
 * scheduled jobs (workflow timeouts and retries among them) and webhook
 * deliveries were never processed in an installed project. Each package now
 * names its processes; this lists them all, one line each.
 */
final class WorkerProcessesCommandTest extends TestCase
{
    #[Test]
    public function every_source_is_listed_one_process_a_line_with_its_pause_first(): void
    {
        $tester = $this->runCommand([WorkerProcessesFixtureQueue::class, WorkerProcessesFixtureScheduler::class]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("5 queue:work nats events\n60 scheduler:plan\n5 scheduler:work\n", $tester->getDisplay());
    }

    #[Test]
    public function a_process_two_sources_name_runs_once(): void
    {
        $tester = $this->runCommand([WorkerProcessesFixtureQueue::class, WorkerProcessesFixtureQueueAgain::class]);

        self::assertSame("5 queue:work nats events\n", $tester->getDisplay());
    }

    /** A broken mail config must not stop the scheduler: the failure is said, the rest still run. */
    #[Test]
    public function a_failing_source_is_reported_and_the_others_are_still_listed(): void
    {
        $tester = $this->runCommand([WorkerProcessesFixtureBroken::class, WorkerProcessesFixtureScheduler::class]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("60 scheduler:plan\n5 scheduler:work\n", $tester->getDisplay());
        self::assertSame('worker:processes: ' . WorkerProcessesFixtureBroken::class . " failed: MAIL_QUEUE is not readable\n", $tester->getErrorOutput());
    }

    #[Test]
    public function when_nothing_could_be_read_it_fails_so_the_worker_falls_back(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand([WorkerProcessesFixtureBroken::class])->getStatusCode());
    }

    /**
     * tk-nats-consumer-name-collision: a listener that names the mail queue
     * puts its messages where mail:work reads, and mail where queue:work reads.
     * Each failed the other's messages. Neither runs now, and the clash is said.
     */
    #[Test]
    public function two_kinds_of_worker_on_one_queue_are_both_refused_and_the_clash_is_said(): void
    {
        $tester = $this->runCommand([
            WorkerProcessesFixtureMailQueue::class,
            WorkerProcessesFixtureMailer::class,
            WorkerProcessesFixtureScheduler::class,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("60 scheduler:plan\n5 scheduler:work\n", $tester->getDisplay());
        self::assertSame(
            "worker:processes: queue 'mail' on nats is read by 'queue:work nats mail' (" . WorkerProcessesFixtureMailQueue::class
            . ") and 'mail:work nats mail' (" . WorkerProcessesFixtureMailer::class
            . "): each would take messages only another can read. None of them runs; give each its own queue.\n",
            $tester->getErrorOutput(),
        );
    }

    /**
     * A source failed and the rest was refused: the listing still succeeded.
     * A failed one makes the worker fall back to queue:work, which would put
     * a refused queue's worker back (review).
     */
    #[Test]
    public function a_listing_that_only_refused_is_not_a_failed_one(): void
    {
        $tester = $this->runCommand([
            WorkerProcessesFixtureBroken::class,
            WorkerProcessesFixtureMailQueue::class,
            WorkerProcessesFixtureMailer::class,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    /** The same kind twice on one queue is two consumers of one queue, which is fine. */
    #[Test]
    public function one_kind_of_worker_named_twice_on_a_queue_is_not_a_clash(): void
    {
        $tester = $this->runCommand([WorkerProcessesFixtureMailer::class, WorkerProcessesFixtureSlowMailer::class]);

        self::assertSame("5 mail:work nats mail\n30 mail:work nats mail\n", $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    #[Test]
    public function json_names_the_source_of_each_process(): void
    {
        $tester = $this->runCommand([WorkerProcessesFixtureScheduler::class], ['--json' => true]);

        self::assertSame([
            ['pause' => 60, 'command' => ['scheduler:plan'], 'source' => WorkerProcessesFixtureScheduler::class],
            ['pause' => 5, 'command' => ['scheduler:work'], 'source' => WorkerProcessesFixtureScheduler::class],
        ], json_decode($tester->getDisplay(), true));
    }

    /** The worker container splits each line on spaces: an argument is one plain word. */
    #[Test]
    public function a_process_argument_with_a_space_or_a_shell_character_is_refused(): void
    {
        foreach (['two words', 'a;b', '$(id)', ''] as $bad) {
            try {
                new WorkerProcess(['mail:work', $bad]);
                self::fail("'{$bad}' was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('one plain word', $e->getMessage());
            }
        }
        self::assertSame('5 media:work nats media.thumbs', (new WorkerProcess(['media:work', 'nats', 'media.thumbs']))->line());
    }

    /**
     * @param list<class-string> $sources
     * @param array<string, mixed> $input
     */
    private function runCommand(array $sources, array $input = []): CommandTester
    {
        $discovery = (new \ReflectionClass(WorkerProcessesFixtureDiscovery::class))->newInstanceWithoutConstructor();
        $discovery->classes = $sources;
        $command = new WorkerProcessesCommand();
        (new \ReflectionProperty($command, 'classDiscovery'))->setValue($command, $discovery);
        (new \ReflectionProperty($command, 'container'))->setValue($command, new WorkerProcessesFixtureContainer());
        $tester = new CommandTester($command);
        $tester->execute($input, ['decorated' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }
}

/** Discovery that has already run and found these classes. */
final class WorkerProcessesFixtureDiscovery extends ClassDiscovery
{
    /** @var list<class-string> */
    public array $classes = [];

    public function initialize(): void
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}

final class WorkerProcessesFixtureContainer implements ContainerInterface
{
    public function get(string $id): object
    {
        return new $id();
    }

    public function has(string $id): bool
    {
        return class_exists($id);
    }
}

final class WorkerProcessesFixtureQueue implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['queue:work', 'nats', 'events'])];
    }
}

final class WorkerProcessesFixtureQueueAgain implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['queue:work', 'nats', 'events'])];
    }
}

final class WorkerProcessesFixtureScheduler implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['scheduler:plan'], 60), new WorkerProcess(['scheduler:work'])];
    }
}

final class WorkerProcessesFixtureBroken implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        throw new \RuntimeException('MAIL_QUEUE is not readable');
    }
}

final class WorkerProcessesFixtureMailQueue implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['queue:work', 'nats', 'mail'], reads: ['nats', 'mail'])];
    }
}

final class WorkerProcessesFixtureMailer implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['mail:work', 'nats', 'mail'], reads: ['nats', 'mail'])];
    }
}

final class WorkerProcessesFixtureSlowMailer implements WorkerProcessSourceInterface
{
    public function workerProcesses(): array
    {
        return [new WorkerProcess(['mail:work', 'nats', 'mail'], 30, ['nats', 'mail'])];
    }
}
