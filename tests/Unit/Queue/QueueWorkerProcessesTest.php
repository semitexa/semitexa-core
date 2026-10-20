<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\Queue\QueueWorkerProcesses;
use Semitexa\Core\Queue\WorkerProcess;
use Semitexa\Core\Tests\Support\StaticState;

/**
 * The queue:work processes the worker container runs (tk-pip-no-queue-worker):
 * one per transport and queue an installed project publishes to. queue:work
 * consumes one queue, and the demo's own listeners publish to 'async', not the
 * default 'events': a worker on the default queue alone left them unconsumed.
 */
final class QueueWorkerProcessesTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $diagnostics;

    protected function setUp(): void
    {
        // The Async handler with a queue below is reported to the process-wide collector.
        $this->diagnostics = StaticState::snapshot(BootDiagnostics::class);
        foreach (['EVENTS_TRANSPORT', 'EVENTS_QUEUE_DEFAULT'] as $key) {
            $this->env[$key] = getenv($key);
        }
        putenv('EVENTS_TRANSPORT=nats');
        putenv('EVENTS_QUEUE_DEFAULT');
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->diagnostics);
        foreach ($this->env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    #[Test]
    public function it_lists_the_default_queue_and_every_queue_a_queued_listener_or_handler_declares(): void
    {
        $lines = $this->queues([
            ['class' => 'App\\Shop\\SendReceipt', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => 'mail'],
            ['class' => 'App\\Shop\\Audit', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => null],
            ['class' => 'App\\Shop\\Ledger', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => 'database', 'queue' => 'ledger'],
            ['class' => 'App\\Shop\\Toast', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'async', 'transport' => null, 'queue' => 'never-queued'],
        ], [
            ['class' => 'App\\Shop\\ExportHandler', 'payload' => 'App\\Shop\\ExportPayload', 'resource' => 'App\\Shop\\ExportResource', 'execution' => 'queued', 'transport' => null, 'queue' => 'exports', 'priority' => 0],
            ['class' => 'App\\Shop\\PreviewHandler', 'payload' => 'App\\Shop\\PreviewPayload', 'resource' => 'App\\Shop\\PreviewResource', 'execution' => 'async', 'transport' => null, 'queue' => 'never-queued', 'priority' => 0],
            ['class' => 'App\\Shop\\PageHandler', 'payload' => 'App\\Shop\\PagePayload', 'resource' => 'App\\Shop\\PageResource', 'execution' => 'sync', 'transport' => null, 'queue' => 'never-queued', 'priority' => 0],
        ]);

        self::assertSame(['5 queue:work database ledger', '5 queue:work nats events', '5 queue:work nats exports', '5 queue:work nats mail'], $lines);
    }

    /** So worker:processes can refuse another kind of worker on the same queue. */
    #[Test]
    public function each_queue_worker_says_which_queue_it_reads(): void
    {
        $processes = $this->processes([
            ['class' => 'App\\Shop\\Ledger', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => 'database', 'queue' => 'ledger'],
        ], []);

        self::assertSame([['database', 'ledger'], ['nats', 'events']], array_map(static fn (WorkerProcess $p): ?array => $p->reads, $processes));
    }

    #[Test]
    public function with_nothing_queued_the_default_queue_is_still_listed_so_one_worker_runs(): void
    {
        self::assertSame(['5 queue:work nats events'], $this->queues([], []));
    }

    #[Test]
    public function the_default_queue_follows_events_queue_default(): void
    {
        putenv('EVENTS_QUEUE_DEFAULT=async');

        $lines = $this->queues([
            ['class' => 'App\\Shop\\Audit', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => null],
        ], []);

        self::assertSame(['5 queue:work nats async'], $lines);
    }

    /**
     * A queue name the worker container cannot pass as one word is left out
     * and said; the other queues still get their worker (review).
     */
    #[Test]
    public function a_queue_name_that_is_not_one_word_is_skipped_and_the_others_still_run(): void
    {
        $lines = $this->queues([
            ['class' => 'App\\Shop\\Odd', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => 'two words'],
            ['class' => 'App\\Shop\\Audit', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => 'audit'],
        ], []);

        self::assertSame(['5 queue:work nats audit', '5 queue:work nats events'], $lines);
    }

    /** A queue only this process can read gets no worker: nothing could consume it there. */
    #[Test]
    public function a_process_local_transport_gets_no_worker(): void
    {
        $lines = $this->queues([
            ['class' => 'App\\Shop\\Audit', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => 'in-memory', 'queue' => 'audit'],
        ], []);

        self::assertSame(['5 queue:work nats events'], $lines);
    }

    /**
     * @param list<array{class: string, event: string, execution: string, transport: ?string, queue: ?string}> $listeners
     * @param list<array{class: string, payload: string, resource: string, execution: string, transport: ?string, queue: ?string, priority: int}> $handlers
     * @return list<string> the lines worker:processes prints for them
     */
    private function queues(array $listeners, array $handlers): array
    {
        return array_map(static fn (WorkerProcess $p): string => $p->line(), $this->processes($listeners, $handlers));
    }

    /**
     * @param list<array{class: string, event: string, execution: string, transport: ?string, queue: ?string}> $listeners
     * @param list<array{class: string, payload: string, resource: string, execution: string, transport: ?string, queue: ?string, priority: int}> $handlers
     * @return list<WorkerProcess>
     */
    private function processes(array $listeners, array $handlers): array
    {
        $registry = (new \ReflectionClass(EventListenerRegistry::class))->newInstanceWithoutConstructor();
        $byEvent = [];
        foreach ($listeners as $meta) {
            $byEvent[$meta['event']][] = $meta + ['priority' => 0, 'maxRetries' => 0, 'retryDelay' => 0];
        }
        (new \ReflectionProperty(EventListenerRegistry::class, 'listenersByEvent'))->setValue($registry, $byEvent);
        (new \ReflectionProperty(EventListenerRegistry::class, 'built'))->setValue($registry, true);

        $handlerRegistry = new HandlerRegistry();
        foreach ($handlers as $meta) {
            $handlerRegistry->register($meta['payload'], $meta['resource'], $meta);
        }

        $source = new QueueWorkerProcesses();
        (new \ReflectionProperty($source, 'attributeDiscovery'))->setValue($source, new QueueQueuesFixtureDiscovery($handlerRegistry));
        (new \ReflectionProperty($source, 'eventListenerRegistry'))->setValue($source, $registry);

        return $source->workerProcesses();
    }
}

/** What discovery holds once it has run: the handlers, nothing else. */
final class QueueQueuesFixtureDiscovery extends AttributeDiscovery
{
    public function __construct(private readonly HandlerRegistry $handlers)
    {
    }

    public function initialize(): void
    {
    }

    public function getHandlerRegistry(): HandlerRegistry
    {
        return $this->handlers;
    }
}
