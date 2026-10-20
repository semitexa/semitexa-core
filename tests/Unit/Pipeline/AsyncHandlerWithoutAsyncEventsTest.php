<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Pipeline\PipelineExecutor;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Queue\QueueTransportRegistry;
use Semitexa\Core\Queue\Transport\InMemoryTransport;
use Semitexa\Core\Request;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Tests\Support\StaticState;

/**
 * REGRESSION (tk-async-handler-without-async-events). With EVENTS_ASYNC=0 the
 * queue transport is in-memory: one per worker process, and nothing in that
 * process consumes it. An #[AsPayloadHandler(execution: Async)] handler was
 * published into it and never ran. It now runs in the process, after the
 * response, as a worker would run it: on copies, so the response it was sent
 * with stays as it was, and a failure is logged, not thrown.
 */
final class AsyncHandlerWithoutAsyncEventsTest extends TestCase
{
    /** @var list<string> */
    private array $errors = [];

    private ?LoggerInterface $loggerSnapshot = null;

    protected function setUp(): void
    {
        AsyncProbeLog::$calls = [];
        AsyncProbeLog::$received = [];
        $logger = (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->getValue();
        $this->loggerSnapshot = $logger instanceof LoggerInterface ? $logger : null;
        $this->errors = [];
        $errors = &$this->errors;
        StaticLoggerBridge::set(new class ($errors) implements LoggerInterface {
            /** @param list<string> $errors */
            public function __construct(private array &$errors) {}
            public function error(string $message, array $context = []): void { $this->errors[] = $message . ': ' . ($context['message'] ?? ''); }
            public function critical(string $message, array $context = []): void { $this->errors[] = $message; }
            public function warning(string $message, array $context = []): void {}
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        });
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->loggerSnapshot);
    }

    #[Test]
    public function without_async_events_an_async_handler_runs_on_copies_and_the_response_is_untouched(): void
    {
        $this->withoutAsyncEvents(function (): void {
            $context = $this->context([AsyncProbeHandler::class]);
            $context->requestDto->setOrderId('o-7');
            $resource = $context->resourceDto;

            $this->executor()->execute($context);

            self::assertSame([AsyncProbeHandler::class], AsyncProbeLog::$calls);
            [$payload, $handledResource] = AsyncProbeLog::$received[0];
            self::assertNotSame($context->requestDto, $payload, 'a copy, as a worker would get');
            self::assertSame('o-7', $payload->getOrderId());
            self::assertNotSame($resource, $handledResource);
            self::assertSame($resource, $context->resourceDto);
            self::assertSame('', $resource->getStatus(), 'the handler wrote to its copy, not to the response');
            self::assertSame([], $this->errors);
            $inMemory = (new \ReflectionProperty(InMemoryTransport::class, 'queues'))->getValue(QueueTransportRegistry::create('in-memory'));
            self::assertSame([], array_filter($inMemory), 'run, and not published as well');
        });
    }

    /** A handoff that did not happen leaves no trace claiming it did. */
    #[Test]
    public function a_handler_that_cannot_be_scheduled_is_logged_and_leaves_no_queued_mark(): void
    {
        $this->withoutAsyncEvents(function (): void {
            $context = $this->context([AsyncProbeHandler::class]);
            $context->resourceDto = null;
            $tracer = new AsyncProbeTracer();

            $this->executor($tracer)->execute($context);

            self::assertSame([], AsyncProbeLog::$calls);
            self::assertSame(['Async handler failed: Async handler ' . AsyncProbeHandler::class . ' requires a resource DTO, but none was provided.'], $this->errors);
            self::assertSame([], $tracer->queuedInProcess());
        });
    }

    #[Test]
    public function a_scheduled_handler_is_marked_as_queued_in_process(): void
    {
        $this->withoutAsyncEvents(function (): void {
            $tracer = new AsyncProbeTracer();

            $this->executor($tracer)->execute($this->context([AsyncProbeHandler::class]));

            self::assertSame([['handler' => AsyncProbeHandler::class]], $tracer->queuedInProcess());
        });
    }

    #[Test]
    public function inside_a_request_it_runs_after_the_request_in_the_order_declared(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('needs Swoole coroutines');
        }
        $this->withoutAsyncEvents(function (): void {
            $seenDuringRequest = null;
            \Swoole\Coroutine\run(function () use (&$seenDuringRequest): void {
                $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
                \Swoole\Coroutine::getContext()[$key] = true;
                $this->executor()->execute($this->context([AsyncProbeHandler::class, AsyncProbeSecondHandler::class]));
                $seenDuringRequest = AsyncProbeLog::$calls;
            });

            self::assertSame([], $seenDuringRequest, 'not inside the request: it must not hold the response');
            self::assertSame([AsyncProbeHandler::class, AsyncProbeSecondHandler::class], AsyncProbeLog::$calls);
        });
    }

    #[Test]
    public function a_failing_async_handler_is_logged_and_the_request_completes(): void
    {
        $this->withoutAsyncEvents(function (): void {
            $context = $this->context([AsyncProbeThrowingHandler::class, AsyncProbeHandler::class]);

            $this->executor()->execute($context);

            self::assertSame([AsyncProbeThrowingHandler::class, AsyncProbeHandler::class], AsyncProbeLog::$calls);
            self::assertSame(['Async handler failed: export failed'], $this->errors);
        });
    }

    #[Test]
    public function with_a_transport_a_worker_reads_the_handler_is_published_not_run(): void
    {
        $state = StaticState::snapshot(QueueTransportRegistry::class);
        AsyncProbeSpyTransport::$published = [];
        QueueTransportRegistry::register('async-probe-spy', new AsyncProbeSpyTransportFactory());
        try {
            $this->withEnv(['EVENTS_ASYNC' => '1', 'EVENTS_TRANSPORT' => 'async-probe-spy'], function (): void {
                $this->executor()->execute($this->context([AsyncProbeHandler::class]));

                self::assertSame([], AsyncProbeLog::$calls);
                self::assertSame(['events'], array_column(AsyncProbeSpyTransport::$published, 0));
                self::assertSame(AsyncProbeHandler::class, json_decode(AsyncProbeSpyTransport::$published[0][1], true)['handler']);
            });
        } finally {
            StaticState::restore($state);
        }
    }

    private function withoutAsyncEvents(callable $run): void
    {
        // Named, not unset: an unset value falls back to the project's .env.
        $this->withEnv(['EVENTS_ASYNC' => '0', 'EVENTS_TRANSPORT' => 'in-memory'], $run);
    }

    /** @param array<string, ?string> $values null unsets */
    private function withEnv(array $values, callable $run): void
    {
        $saved = [];
        foreach ($values as $key => $value) {
            $saved[$key] = getenv($key);
            putenv($value === null ? $key : "{$key}={$value}");
        }
        try {
            $run();
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
            }
        }
    }

    private function executor(?AsyncProbeTracer $tracer = null): PipelineExecutor
    {
        return new PipelineExecutor(
            new AsyncProbeContainer([
                AsyncProbeHandler::class => new AsyncProbeHandler(),
                AsyncProbeSecondHandler::class => new AsyncProbeSecondHandler(),
                AsyncProbeThrowingHandler::class => new AsyncProbeThrowingHandler(),
            ]),
            new AsyncProbeContainer([PipelineListenerRegistry::class => new AsyncProbeNoListeners()]),
            $tracer,
        );
    }

    /** @param list<class-string> $handlers */
    private function context(array $handlers): RequestPipelineContext
    {
        $route = new DiscoveredRoute(
            path: '/export',
            methods: ['POST'],
            name: 'export',
            requestClass: AsyncProbePayload::class,
            responseClass: AsyncProbeResource::class,
            handlers: array_map(static fn (string $h): array => ['class' => $h, 'execution' => 'async'], $handlers),
            type: 'http-request',
            transport: null,
            produces: null,
            consumes: null,
            module: 'core',
        );

        return new RequestPipelineContext(
            requestDto: new AsyncProbePayload(),
            route: $route,
            request: new Request('POST', '/export', [], [], [], [], []),
            resourceDto: new AsyncProbeResource(),
            authBootstrapper: null,
        );
    }
}

final class AsyncProbeLog
{
    /** @var list<class-string> */
    public static array $calls = [];

    /** @var list<array{AsyncProbePayload, AsyncProbeResource}> */
    public static array $received = [];
}

final class AsyncProbePayload
{
    private string $orderId = '';

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }
}

final class AsyncProbeResource implements ResourceInterface
{
    private string $status = '';

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}

final class AsyncProbeHandler implements TypedHandlerInterface
{
    public function handle(AsyncProbePayload $payload, AsyncProbeResource $resource): AsyncProbeResource
    {
        AsyncProbeLog::$calls[] = self::class;
        AsyncProbeLog::$received[] = [$payload, $resource];
        $resource->setStatus('exported');

        return $resource;
    }
}

final class AsyncProbeSecondHandler implements TypedHandlerInterface
{
    public function handle(AsyncProbePayload $payload, AsyncProbeResource $resource): AsyncProbeResource
    {
        AsyncProbeLog::$calls[] = self::class;

        return $resource;
    }
}

final class AsyncProbeThrowingHandler implements TypedHandlerInterface
{
    public function handle(AsyncProbePayload $payload, AsyncProbeResource $resource): AsyncProbeResource
    {
        AsyncProbeLog::$calls[] = self::class;

        throw new \RuntimeException('export failed');
    }
}

final class AsyncProbeContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            throw new class("no binding for {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

/** Duck-typed stand-in: PipelineExecutor only calls getListeners(). */
final class AsyncProbeNoListeners
{
    /** @return list<array{class: class-string, phase: string, priority: int}> */
    public function getListeners(string $phaseClass): array
    {
        return [];
    }
}

final class AsyncProbeSpyTransport implements QueueTransportInterface
{
    /** @var list<array{string, string}> */
    public static array $published = [];

    public function publish(string $queueName, string $payload): void
    {
        self::$published[] = [$queueName, $payload];
    }

    public function consume(string $queueName, callable $callback): void
    {
    }
}

final class AsyncProbeSpyTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new AsyncProbeSpyTransport();
    }
}

/** Records the marks; spans are not what these tests are about. */
final class AsyncProbeTracer implements RequestTracerInterface
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $marks = [];

    public function begin(string $name, array $context = []): void
    {
    }

    public function end(string $name, array $context = []): void
    {
    }

    public function mark(string $name, array $context = []): void
    {
        $this->marks[] = [$name, $context];
    }

    /** @return list<array<string, mixed>> the context of each pipeline.handler.queued_in_process mark */
    public function queuedInProcess(): array
    {
        return array_values(array_map(
            static fn (array $m): array => $m[1],
            array_filter($this->marks, static fn (array $m): bool => $m[0] === 'pipeline.handler.queued_in_process'),
        ));
    }
}
