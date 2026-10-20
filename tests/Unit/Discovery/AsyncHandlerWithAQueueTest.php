<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\BootWarning;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Tests\Support\StaticState;

/**
 * Async meant "through a queue" until 2026-10 (tk-handler-execution-sync-async-queued).
 * A handler that still names a queue, a transport or retries now runs in the
 * process that served the request and the queue goes unused: said at boot.
 */
final class AsyncHandlerWithAQueueTest extends TestCase
{
    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $diagnostics;

    protected function setUp(): void
    {
        // begin() replaces the process-wide collector: put back as found.
        $this->diagnostics = StaticState::snapshot(BootDiagnostics::class);
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->diagnostics);
    }

    #[Test]
    public function an_async_handler_with_a_queue_is_told_to_be_queued(): void
    {
        self::assertSame([
            'Handler ' . AsyncWithQueueHandler::class . ' is Async with a transport, queue or retries, which apply only to Queued: '
                . 'Async runs in the process that served the request. For a worker, set execution: HandlerExecution::Queued.',
        ], $this->invalidUsages(AsyncWithQueueHandler::class));
    }

    #[Test]
    public function an_async_handler_without_one_or_a_queued_handler_with_one_is_not(): void
    {
        self::assertSame([], $this->invalidUsages(AsyncPlainHandler::class));
        self::assertSame([], $this->invalidUsages(QueuedWithQueueHandler::class));
    }

    /** @return list<string> */
    private function invalidUsages(string $handlerClass): array
    {
        $diagnostics = BootDiagnostics::begin();
        $discovery = new AttributeDiscovery($this->discoveryReturning($handlerClass), new ModuleRegistry(), new RouteRegistry());
        // The route its payload would have: discovery refuses a handler whose payload has none.
        (new \ReflectionProperty(AttributeDiscovery::class, 'httpRequests'))->setValue($discovery, [QueueProbePayload::class => []]);
        (new \ReflectionMethod(AttributeDiscovery::class, 'discoverHandlers'))->invoke($discovery, $diagnostics);
        // An empty list below must mean "registered without a word", not "never registered".
        self::assertSame($handlerClass, $discovery->getHandlerRegistry()->getHandlerByClass($handlerClass)['class'] ?? null, 'the handler was registered');

        return array_values(array_map(
            static fn (BootWarning $w): string => $w->message,
            array_filter($diagnostics->getWarnings(), static fn (BootWarning $w): bool => $w->component === 'HandlerRegistry'),
        ));
    }

    private function discoveryReturning(string $class): ClassDiscovery
    {
        return new class ($class) extends ClassDiscovery {
            public function __construct(private readonly string $class)
            {
            }

            public function initialize(): void
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return $attributeClass === AsPayloadHandler::class ? [$this->class] : [];
            }
        };
    }
}

final class QueueProbePayload
{
}

final class QueueProbeResource
{
}

#[AsPayloadHandler(payload: QueueProbePayload::class, resource: QueueProbeResource::class, execution: HandlerExecution::Async, queue: 'reports')]
final class AsyncWithQueueHandler
{
}

#[AsPayloadHandler(payload: QueueProbePayload::class, resource: QueueProbeResource::class, execution: HandlerExecution::Async)]
final class AsyncPlainHandler
{
}

#[AsPayloadHandler(payload: QueueProbePayload::class, resource: QueueProbeResource::class, execution: HandlerExecution::Queued, queue: 'reports', maxRetries: 3)]
final class QueuedWithQueueHandler
{
}
