<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\QueueQueuesCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\HandlerRegistry;
use Semitexa\Core\Event\EventListenerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * queue:queues — every transport and queue an installed project's worker has
 * to consume (tk-pip-no-queue-worker). queue:work consumes one queue, and the
 * demo's own listeners publish to 'async' and 'demo.notifications', not the
 * default 'events': a worker on the default queue alone left them unconsumed.
 */
final class QueueQueuesCommandTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        foreach (['EVENTS_TRANSPORT', 'EVENTS_QUEUE_DEFAULT'] as $key) {
            $this->env[$key] = getenv($key);
        }
        putenv('EVENTS_TRANSPORT=nats');
        putenv('EVENTS_QUEUE_DEFAULT');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    #[Test]
    public function it_lists_the_default_queue_and_every_queue_a_queued_listener_or_async_handler_declares(): void
    {
        $tester = $this->queues([
            ['class' => 'App\\Shop\\SendReceipt', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => 'mail'],
            ['class' => 'App\\Shop\\Audit', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => null],
            ['class' => 'App\\Shop\\Ledger', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => 'database', 'queue' => 'ledger'],
            ['class' => 'App\\Shop\\Toast', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'async', 'transport' => null, 'queue' => 'never-queued'],
        ], [
            ['class' => 'App\\Shop\\ExportHandler', 'payload' => 'App\\Shop\\ExportPayload', 'resource' => 'App\\Shop\\ExportResource', 'execution' => 'async', 'transport' => null, 'queue' => 'exports', 'priority' => 0],
            ['class' => 'App\\Shop\\PageHandler', 'payload' => 'App\\Shop\\PagePayload', 'resource' => 'App\\Shop\\PageResource', 'execution' => 'sync', 'transport' => null, 'queue' => 'never-queued', 'priority' => 0],
        ], []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame("database ledger\nnats events\nnats exports\nnats mail\n", $tester->getDisplay());
    }

    #[Test]
    public function with_nothing_queued_the_default_queue_is_still_listed_so_one_worker_runs(): void
    {
        self::assertSame("nats events\n", $this->queues([], [], [])->getDisplay());
    }

    #[Test]
    public function the_default_queue_follows_events_queue_default(): void
    {
        putenv('EVENTS_QUEUE_DEFAULT=async');

        $tester = $this->queues([
            ['class' => 'App\\Shop\\Audit', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => null],
        ], [], []);

        self::assertSame("nats async\n", $tester->getDisplay());
    }

    #[Test]
    public function json_names_what_consumes_each_queue(): void
    {
        $tester = $this->queues([
            ['class' => 'App\\Shop\\SendReceipt', 'event' => 'App\\Shop\\OrderPaid', 'execution' => 'queued', 'transport' => null, 'queue' => 'mail'],
        ], [
            ['class' => 'App\\Shop\\MailHandler', 'payload' => 'App\\Shop\\MailPayload', 'resource' => 'App\\Shop\\MailResource', 'execution' => 'async', 'transport' => null, 'queue' => 'mail', 'priority' => 0],
        ], ['--json' => true]);

        self::assertSame([
            ['transport' => 'nats', 'queue' => 'events', 'declared_by' => []],
            ['transport' => 'nats', 'queue' => 'mail', 'declared_by' => ['App\\Shop\\MailHandler', 'App\\Shop\\SendReceipt']],
        ], json_decode($tester->getDisplay(), true));
    }

    /**
     * @param list<array{class: string, event: string, execution: string, transport: ?string, queue: ?string}> $listeners
     * @param list<array{class: string, payload: string, resource: string, execution: string, transport: ?string, queue: ?string, priority: int}> $handlers
     * @param array<string, mixed> $input
     */
    private function queues(array $listeners, array $handlers, array $input): CommandTester
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

        $command = new QueueQueuesCommand();
        (new \ReflectionProperty($command, 'attributeDiscovery'))->setValue($command, new QueueQueuesFixtureDiscovery($handlerRegistry));
        (new \ReflectionProperty($command, 'eventListenerRegistry'))->setValue($command, $registry);
        $tester = new CommandTester($command);
        $tester->execute($input, ['decorated' => false]);

        return $tester;
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
