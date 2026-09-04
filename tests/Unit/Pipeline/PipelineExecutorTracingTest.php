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
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\HandlerReflectionCache;
use Semitexa\Core\Pipeline\PipelineExecutor;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\PipelineListenerRegistry;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Core\Request;

/**
 * The pipeline seen from the inside: a span per phase, per listener and per
 * handler, each naming the class it ran, closed even when it throws.
 *
 * Without these the pipeline was one opaque span holding ~89% of a warm page
 * request. The assertions here are on ORDER and NAMES — durations are the
 * tracer's business.
 */
final class PipelineExecutorTracingTest extends TestCase
{
    protected function setUp(): void
    {
        // Boot does this for discovered handlers; the executor refuses an
        // un-warmed one on purpose.
        foreach ([TracingHandlerA::class, TracingHandlerB::class, TracingThrowingHandler::class] as $h) {
            HandlerReflectionCache::warm($h);
        }
    }

    protected function tearDown(): void
    {
        HandlerReflectionCache::reset();
    }

    #[Test]
    public function every_phase_listener_and_handler_is_a_named_span_in_call_order(): void
    {
        $tracer = new PipelineRecordingTracer();
        $context = $this->context([TracingHandlerA::class, TracingHandlerB::class]);

        $this->executor($tracer, [AuthCheck::class => [TracingListener::class]])->execute($context);

        self::assertSame([
            'begin pipeline.auth_check',
            'begin pipeline.listener',
            'end pipeline.listener',
            'end pipeline.auth_check',
            'begin pipeline.handle_request',
            'begin pipeline.handler',
            'end pipeline.handler',
            'begin pipeline.handler',
            'end pipeline.handler',
            'end pipeline.handle_request',
            'begin pipeline.handler_completed',
            'end pipeline.handler_completed',
        ], $tracer->sequence());

        self::assertSame(AuthCheck::class, $tracer->contextOf('begin pipeline.auth_check', 0)['phase']);
        self::assertSame(
            ['listener' => TracingListener::class, 'method' => 'handle'],
            $tracer->contextOf('begin pipeline.listener', 0),
        );
        self::assertSame(
            ['handler' => TracingHandlerA::class, 'method' => 'handle'],
            $tracer->contextOf('begin pipeline.handler', 0),
            'the FIRST handler is named on its own span - not only the last one on the outer span',
        );
        self::assertSame(TracingHandlerB::class, $tracer->contextOf('begin pipeline.handler', 1)['handler']);
        self::assertSame(['dispatched' => false], $tracer->contextOf('end pipeline.handler_completed', 0));
        self::assertSame(TracingHandlerB::class, $context->lastHandlerClass);
    }

    #[Test]
    public function a_handler_that_throws_still_closes_its_span(): void
    {
        $tracer = new PipelineRecordingTracer();
        $context = $this->context([TracingThrowingHandler::class]);

        try {
            $this->executor($tracer, [])->execute($context);
            self::fail('the handler must throw');
        } catch (\RuntimeException $e) {
            self::assertSame('handler blew up', $e->getMessage());
        }

        self::assertSame([
            'begin pipeline.auth_check',
            'end pipeline.auth_check',
            'begin pipeline.handle_request',
            'begin pipeline.handler',
            'end pipeline.handler',
            'end pipeline.handle_request',
        ], $tracer->sequence(), 'finally closes the handler and the phase; handler_completed never opens');
    }

    #[Test]
    public function a_missing_handler_class_is_a_mark_not_a_span(): void
    {
        $tracer = new PipelineRecordingTracer();
        $context = $this->context(['App\\Nope\\MissingHandler']);

        $this->executor($tracer, [])->execute($context);

        self::assertContains('mark pipeline.handler.missing', $tracer->sequence());
        self::assertNotContains('begin pipeline.handler', $tracer->sequence());
    }

    #[Test]
    public function without_a_tracer_the_pipeline_runs_exactly_as_before(): void
    {
        $context = $this->context([TracingHandlerA::class]);

        (new PipelineExecutor($this->requestScope(), $this->container([]), null))->execute($context);

        self::assertSame(TracingHandlerA::class, $context->lastHandlerClass);
    }

    /**
     * @param array<class-string, list<class-string>> $listenersByPhase
     */
    private function executor(RequestTracerInterface $tracer, array $listenersByPhase): PipelineExecutor
    {
        return new PipelineExecutor($this->requestScope(), $this->container($listenersByPhase), $tracer);
    }

    private function requestScope(): ContainerInterface
    {
        return new TracingArrayContainer([
            TracingListener::class => new TracingListener(),
            TracingHandlerA::class => new TracingHandlerA(),
            TracingHandlerB::class => new TracingHandlerB(),
            TracingThrowingHandler::class => new TracingThrowingHandler(),
        ]);
    }

    /**
     * @param array<class-string, list<class-string>> $listenersByPhase
     */
    private function container(array $listenersByPhase): ContainerInterface
    {
        return new TracingArrayContainer([
            PipelineListenerRegistry::class => new TracingStubListenerRegistry($listenersByPhase),
        ]);
    }

    /**
     * @param list<class-string> $handlers
     */
    private function context(array $handlers): RequestPipelineContext
    {
        $route = new DiscoveredRoute(
            path: '/traced',
            methods: ['GET'],
            name: 'traced',
            requestClass: TracingPayload::class,
            responseClass: TracingResource::class,
            handlers: array_map(static fn (string $h): array => ['class' => $h, 'execution' => 'sync'], $handlers),
            type: 'http-request',
            transport: null,
            produces: null,
            consumes: null,
            module: 'core',
        );

        return new RequestPipelineContext(
            requestDto: new TracingPayload(),
            route: $route,
            request: new Request('GET', '/traced', [], [], [], [], []),
            resourceDto: new TracingResource(),
            authBootstrapper: null,
        );
    }
}

final class PipelineRecordingTracer implements RequestTracerInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $calls = [];

    public function begin(string $name, array $context = []): void
    {
        $this->calls[] = ['begin ' . $name, $context];
    }

    public function end(string $name, array $context = []): void
    {
        $this->calls[] = ['end ' . $name, $context];
    }

    public function mark(string $name, array $context = []): void
    {
        $this->calls[] = ['mark ' . $name, $context];
    }

    /** @return list<string> */
    public function sequence(): array
    {
        return array_map(static fn (array $c): string => $c[0], $this->calls);
    }

    /** @return array<string, mixed> */
    public function contextOf(string $call, int $nth): array
    {
        $seen = 0;
        foreach ($this->calls as [$name, $context]) {
            if ($name === $call && $seen++ === $nth) {
                return $context;
            }
        }

        throw new \LogicException("no call {$call} #{$nth}");
    }
}

final class TracingArrayContainer implements ContainerInterface
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
final class TracingStubListenerRegistry
{
    /** @param array<class-string, list<class-string>> $byPhase */
    public function __construct(private array $byPhase)
    {
    }

    /** @return list<array{class: class-string, phase: string, priority: int}> */
    public function getListeners(string $phaseClass): array
    {
        return array_map(
            static fn (string $c): array => ['class' => $c, 'phase' => $phaseClass, 'priority' => 0],
            $this->byPhase[$phaseClass] ?? [],
        );
    }
}

final class TracingListener implements PipelineListenerInterface
{
    public function handle(RequestPipelineContext $context): void
    {
    }
}

final class TracingPayload
{
}

final class TracingResource implements ResourceInterface
{
}

final class TracingHandlerA implements TypedHandlerInterface
{
    public function handle(TracingPayload $payload, TracingResource $resource): TracingResource
    {
        return $resource;
    }
}

final class TracingHandlerB implements TypedHandlerInterface
{
    public function handle(TracingPayload $payload, TracingResource $resource): TracingResource
    {
        return $resource;
    }
}

final class TracingThrowingHandler implements TypedHandlerInterface
{
    public function handle(TracingPayload $payload, TracingResource $resource): TracingResource
    {
        throw new \RuntimeException('handler blew up');
    }
}
