<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Semitexa\Core\Container\ExecutionContext;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Lifecycle\CarriedContext;
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
use Semitexa\Core\Server\AfterResponse;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Tests\Support\StaticState;

/**
 * Where a payload handler runs (tk-handler-execution-sync-async-queued).
 *
 * Queued goes through a queue to a worker. With EVENTS_ASYNC=0 the transport
 * is in-memory: one per worker process, and nothing in that process consumes
 * it, so the handler was published into it and never ran
 * (tk-async-handler-without-async-events). It runs in the process instead,
 * after the response, as a worker would run it: on copies, so the response it
 * was sent with stays as it was, and a failure is logged, not thrown.
 *
 * Async runs in the process always, after the response, with the request's
 * context, and again on every re-run of a live region; Queued is not re-run.
 */
final class AsyncAndQueuedHandlersTest extends TestCase
{
    /** @var list<string> */
    private array $errors = [];

    private ?LoggerInterface $loggerSnapshot = null;

    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $carriedSnapshot;

    protected function setUp(): void
    {
        // The keys a test names with carry() are worker-wide: put back as found.
        $this->carriedSnapshot = StaticState::snapshot(CarriedContext::class);
        AsyncProbeLog::$calls = [];
        AsyncProbeLog::$received = [];
        AsyncProbeContextHandler::$seen = [];
        AsyncProbeContainer::$resolvedWith = [];
        AsyncProbeContextHandler::$cids = [];
        AsyncProbeContextHandler::$container = null;
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
        StaticState::restore($this->carriedSnapshot);
        (new \ReflectionProperty(StaticLoggerBridge::class, 'logger'))->setValue(null, $this->loggerSnapshot);
    }

    #[Test]
    public function without_async_events_a_queued_handler_runs_on_copies_and_the_response_is_untouched(): void
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
    public function a_failing_handler_is_logged_and_the_request_completes(): void
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

    /**
     * tk-deferred-work-on-held-open-connections: a re-run tick of a held-open
     * connection acts on nothing. Its HandlerCompleted broadcast was deferred
     * to the connection's end, one a tick, and its queued handlers ran (or
     * were published) every time the data changed.
     */
    #[Test]
    public function a_re_run_runs_no_queued_handler_and_dispatches_no_handler_completed(): void
    {
        $this->withoutAsyncEvents(function (): void {
            $tracer = new AsyncProbeTracer();

            $this->executor($tracer, reRun: true)->execute($this->context([AsyncProbeHandler::class]));

            self::assertSame([], AsyncProbeLog::$calls);
            self::assertSame([], $tracer->queuedInProcess());
            self::assertSame([
                ['pipeline.handler.skipped', ['handler' => AsyncProbeHandler::class, 'reason' => 're_run']],
                ['pipeline.handler_completed.skipped', ['reason' => 're_run']],
            ], $tracer->marks);
        });
    }

    /** Async meant "through a queue" until 2026-10; it is the process now, whatever the transport. */
    #[Test]
    public function an_async_handler_runs_in_the_process_even_with_a_transport_a_worker_reads(): void
    {
        $state = StaticState::snapshot(QueueTransportRegistry::class);
        AsyncProbeSpyTransport::$published = [];
        QueueTransportRegistry::register('async-probe-spy', new AsyncProbeSpyTransportFactory());
        try {
            $this->withEnv(['EVENTS_ASYNC' => '1', 'EVENTS_TRANSPORT' => 'async-probe-spy'], function (): void {
                $tracer = new AsyncProbeTracer();

                $this->executor($tracer)->execute($this->context([AsyncProbeHandler::class], 'async'));

                self::assertSame([AsyncProbeHandler::class], AsyncProbeLog::$calls);
                self::assertSame([], AsyncProbeSpyTransport::$published);
                self::assertSame([
                    ['pipeline.handler.async', ['handler' => AsyncProbeHandler::class]],
                    ['pipeline.handler_completed.skipped', ['reason' => 'no_handler_ran']],
                ], $tracer->marks);
            });
        } finally {
            StaticState::restore($state);
        }
    }

    /** Its resource is part of the live region's frame, delivered later: a re-run needs it fresh. */
    #[Test]
    public function a_re_run_runs_an_async_handler_again(): void
    {
        $tracer = new AsyncProbeTracer();

        $this->executor($tracer, reRun: true)->execute($this->context([AsyncProbeHandler::class], 'async'));

        self::assertSame([AsyncProbeHandler::class], AsyncProbeLog::$calls);
        self::assertSame([
            ['pipeline.handler.async', ['handler' => AsyncProbeHandler::class]],
            ['pipeline.handler_completed.skipped', ['reason' => 're_run']],
        ], $tracer->marks);
    }

    /**
     * It runs after the request has cleared its context: the container's
     * execution context, and what stores keep beside it (the tenant, the
     * signed-in user, the locale). It gets the request's. A Queued one does
     * not, as a worker would not.
     */
    #[Test]
    public function an_async_handler_runs_in_the_requests_context_and_a_queued_one_does_not(): void
    {
        $this->withoutAsyncEvents(function (): void {
            [$container, $request] = $this->requestContext();
            \Swoole\Coroutine\run(function () use ($container, $request): void {
                $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
                \Swoole\Coroutine::getContext()[$key] = true;
                $container->setExecutionContext(new ExecutionContext(request: $request));
                CoroutineLocal::set('test.tenant', 'acme');
                foreach (['async', 'queued'] as $execution) {
                    $this->executor(container: $container)->execute($this->context([AsyncProbeContextHandler::class], $execution));
                }
                // What the request does once the response is out.
                $container->clearExecutionContext();
                CoroutineLocal::remove('test.tenant');
            });

            self::assertSame([[$request, 'acme'], [null, null]], AsyncProbeContextHandler::$seen);
            self::assertSame(['acme'], AsyncProbeContainer::$resolvedWith, 'the Async one in the request scope (the Queued one in the worker container)');
        });
    }

    /** On a re-run tick it runs in a coroutine of its own, which never had the request's context. */
    #[Test]
    public function on_a_re_run_tick_an_async_handler_still_runs_in_the_requests_context(): void
    {
        [$container, $request] = $this->requestContext();
        $during = null;
        $requestCid = 0;
        \Swoole\Coroutine\run(function () use ($container, $request, &$during, &$requestCid): void {
            $requestCid = (int) \Swoole\Coroutine::getCid();
            $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
            \Swoole\Coroutine::getContext()[$key] = true;
            $container->setExecutionContext(new ExecutionContext(request: $request));
            CoroutineLocal::set('test.tenant', 'acme');
            AfterResponse::tick(fn () => $this->executor(reRun: true, container: $container)->execute($this->context([AsyncProbeContextHandler::class], 'async')));
            $during = AsyncProbeContextHandler::$seen;
            \Swoole\Coroutine::sleep(0.01);
        });

        self::assertSame([], $during, 'after the tick, not in it');
        self::assertSame([[$request, 'acme']], AsyncProbeContextHandler::$seen);
        self::assertNotSame($requestCid, AsyncProbeContextHandler::$cids[0], 'in a coroutine of its own');
    }

    /**
     * Outside a request coroutine it runs inline, and a coroutine of its own
     * may carry its parent's context (an SSE child does): a Queued handler
     * must not see it there either, or it works here and not behind a queue.
     */
    #[Test]
    public function a_queued_handler_run_inline_in_a_coroutine_with_context_does_not_see_it(): void
    {
        $this->withoutAsyncEvents(function (): void {
            [$container, $request] = $this->requestContext();
            $after = null;
            \Swoole\Coroutine\run(function () use ($container, $request, &$after): void {
                $container->setExecutionContext(new ExecutionContext(request: $request));
                CoroutineLocal::set('test.tenant', 'acme');
                $this->executor(container: $container)->execute($this->context([AsyncProbeContextHandler::class], 'queued'));
                $after = [$container->captureExecutionContext()->request, CoroutineLocal::get('test.tenant')];
            });

            self::assertSame([[null, null]], AsyncProbeContextHandler::$seen);
            self::assertSame([$request, 'acme'], $after, 'and the coroutine has its own back');
        });
    }

    /**
     * The request scope is reset before deferred work runs (onRequest's
     * finally), and a reset scope refuses every handler: a Queued one
     * resolved from it never ran. It is resolved as a worker resolves it,
     * from the worker container.
     */
    #[Test]
    public function a_queued_handler_is_resolved_from_the_worker_container_after_the_request_scope_is_reset(): void
    {
        $this->withoutAsyncEvents(function (): void {
            $scope = new AsyncProbeContainer([AsyncProbeHandler::class => new AsyncProbeHandler()]);
            $executor = new PipelineExecutor(
                $scope,
                new AsyncProbeContainer([
                    PipelineListenerRegistry::class => new AsyncProbeNoListeners(),
                    AsyncProbeHandler::class => new AsyncProbeHandler(),
                ]),
            );
            CarriedContext::carry('test.tenant');
            \Swoole\Coroutine\run(function () use ($executor, $scope): void {
                $key = (new \ReflectionClassConstant(SwooleBootstrap::class, 'COROUTINE_CONTEXT_KEY'))->getValue();
                \Swoole\Coroutine::getContext()[$key] = true;
                CoroutineLocal::set('test.tenant', 'acme');
                $executor->execute($this->context([AsyncProbeHandler::class], 'queued'));
                // What onRequest's finally does before the coroutine exits.
                $scope->refuseEverything = true;
            });

            self::assertSame([AsyncProbeHandler::class], AsyncProbeLog::$calls);
            self::assertSame([], $this->errors);
            self::assertSame([null], AsyncProbeContainer::$resolvedWith, 'resolved when it runs, with no request context: what it injects, a worker could not');
        });
    }

    /** @return array{SemitexaContainer, Request} */
    private function requestContext(): array
    {
        $container = new SemitexaContainer();
        $container->set(PipelineListenerRegistry::class, new AsyncProbeNoListeners());
        AsyncProbeContextHandler::$container = $container;
        CarriedContext::carry('test.tenant');

        return [$container, new Request('GET', '/report', [], [], [], [], [])];
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

    private function executor(?AsyncProbeTracer $tracer = null, bool $reRun = false, ?ContainerInterface $container = null): PipelineExecutor
    {
        // Async ones are resolved in the request scope, Queued ones in the
        // worker container: both know them, as in production.
        $handlers = [
            AsyncProbeHandler::class => new AsyncProbeHandler(),
            AsyncProbeSecondHandler::class => new AsyncProbeSecondHandler(),
            AsyncProbeThrowingHandler::class => new AsyncProbeThrowingHandler(),
            AsyncProbeContextHandler::class => new AsyncProbeContextHandler(),
        ];
        if ($container instanceof SemitexaContainer) {
            foreach ($handlers as $class => $handler) {
                $container->set($class, $handler);
            }
        }

        return new PipelineExecutor(
            new AsyncProbeContainer($handlers),
            $container ?? new AsyncProbeContainer([PipelineListenerRegistry::class => new AsyncProbeNoListeners()] + $handlers),
            $tracer,
            $reRun,
        );
    }

    /** @param list<class-string> $handlers */
    private function context(array $handlers, string $execution = 'queued'): RequestPipelineContext
    {
        $route = new DiscoveredRoute(
            path: '/export',
            methods: ['POST'],
            name: 'export',
            requestClass: AsyncProbePayload::class,
            responseClass: AsyncProbeResource::class,
            handlers: array_map(static fn (string $h): array => ['class' => $h, 'execution' => $execution], $handlers),
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

final class AsyncProbeContextHandler implements TypedHandlerInterface
{
    public static ?SemitexaContainer $container = null;

    /** @var list<array{?Request, mixed}> the request in the execution context and the carried tenant, each run */
    public static array $seen = [];

    /** @var list<int> the coroutine each run was in */
    public static array $cids = [];

    public function handle(AsyncProbePayload $payload, AsyncProbeResource $resource): AsyncProbeResource
    {
        self::$seen[] = [self::$container?->captureExecutionContext()->request, CoroutineLocal::get('test.tenant')];
        self::$cids[] = (int) \Swoole\Coroutine::getCid();

        return $resource;
    }
}

final class AsyncProbeContainer implements ContainerInterface
{
    /** @var list<mixed> the carried tenant at each resolution of a handler */
    public static array $resolvedWith = [];

    /** As a RequestScopedContainer does once reset: see RequestScopedContainer::get(). */
    public bool $refuseEverything = false;

    /** @param array<string, object> $services */
    public function __construct(private array $services)
    {
    }

    public function get(string $id): object
    {
        if ($this->refuseEverything) {
            throw new \RuntimeException("a reset request scope refuses {$id}");
        }
        if (str_ends_with($id, 'Handler')) {
            self::$resolvedWith[] = CoroutineLocal::get('test.tenant');
        }
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
