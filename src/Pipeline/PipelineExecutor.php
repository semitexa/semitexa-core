<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Exception\PipelineException;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Event\HandlerCompleted;
use Semitexa\Core\Queue\HandlerExecution;
use Semitexa\Core\Queue\QueueDispatcher;

/**
 * Executes the request pipeline: a fixed sequence of phases (AuthCheck → HandleRequest).
 *
 * Pipeline listeners are always synchronous — the response depends on their result.
 * Domain events (HandlerCompleted) are dispatched after the pipeline completes.
 *
 * ## Tracing
 *
 * The optional tracer sees the pipeline from the inside: one span per phase,
 * one per pipeline listener, one per route handler, one around the
 * HandlerCompleted dispatch. Measured on a warm page request, the pipeline
 * held ~89% of the wall time as a single opaque span; these are the spans
 * that say WHERE inside it the time went. Every span names the class (and
 * method) it ran, so a trace viewer can open the code behind it.
 *
 * Every span closes however its work leaves, and one that was left by an
 * exception closes with `unfinished`: a listener that throws still leaves a
 * trace that shows which listener it was, and says that it died there.
 */
final class PipelineExecutor
{
    public function __construct(
        private readonly ContainerInterface $requestScopedContainer,
        private readonly ContainerInterface $container,
        private readonly ?RequestTracerInterface $tracer = null,
    ) {
    }

    /**
     * Phase marker class → its span name. Data, not derivation: the span name
     * is a wire contract (the trace viewer and ai:observe key on it), so it must
     * not silently follow a class rename.
     */
    private const PHASE_SPANS = [
        AuthCheck::class => 'pipeline.auth_check',
        HandleRequest::class => 'pipeline.handle_request',
    ];

    public function execute(RequestPipelineContext $context): void
    {
        // The root the inner spans nest under lives here, not at the call site,
        // so every entry point that runs a pipeline produces the same tree.
        $this->span('pipeline', ['route' => $context->route->name], function () use ($context): void {
            foreach (self::PHASE_SPANS as $phaseClass => $span) {
                // The short name, not the FQCN: a marker class ran nothing, and a
                // class-shaped value would become a link to an empty file.
                $this->span($span, ['phase' => substr($phaseClass, (int) strrpos($phaseClass, '\\') + 1)], function () use ($phaseClass, $context): void {
                    $this->dispatchPhase($phaseClass, $context);
                });
            }

            $this->span('pipeline.handler_completed', [], function () use ($context): void {
                $this->dispatchHandlerCompleted($context);
            });
        });
    }

    /**
     * Run $work inside a span that closes however $work leaves.
     *
     * The closing context says `unfinished` when $work threw: with the plain
     * `finally { end() }` this replaced, the span closed clean and the reader
     * could not tell a handler that completed from one that died — the flag
     * SafeRequestTracer would have set was pre-empted by the explicit end.
     *
     * @param array<string, mixed> $context
     */
    private function span(string $name, array $context, callable $work): void
    {
        if ($this->tracer === null) {
            $work();

            return;
        }

        $this->tracer->begin($name, $context);
        $ok = false;
        try {
            $work();
            $ok = true;
        } finally {
            $this->tracer->end($name, $ok ? [] : ['unfinished' => true]);
        }
    }

    private function dispatchPhase(string $phaseClass, RequestPipelineContext $context): void
    {
        /** @var PipelineListenerRegistry $registry */
        $registry = $this->container->get(PipelineListenerRegistry::class);
        $listeners = $registry->getListeners($phaseClass);
        foreach ($listeners as $meta) {
            // Resolution inside the span, like handlers: a listener that fails or
            // is slow to construct is charged to that listener and NAMED — the
            // exact case a re-run hits when the execution context is not ready.
            // Entered through handle() by contract (PipelineListenerInterface).
            $this->span('pipeline.listener', ['listener' => $meta['class'], 'method' => 'handle'], function () use ($meta, $context): void {
                $this->invokeListener($this->requestScopedContainer->get($meta['class']), $context);
            });
        }

        if ($phaseClass === HandleRequest::class) {
            $this->executeRouteHandlers($context);
        }
    }

    /**
     * Bridge: dispatches to the appropriate handler contract.
     * TypedHandlerInterface handlers are invoked via reflection cache
     * with concrete payload/resource types.
     * - PipelineListenerInterface: handle($context)
     */
    private function invokeListener(object $instance, RequestPipelineContext $context): void
    {
        if ($instance instanceof TypedHandlerInterface) {
            if ($context->resourceDto === null) {
                throw new PipelineException(sprintf(
                    'TypedHandlerInterface %s requires a resource DTO, but none was provided.',
                    $instance::class,
                ));
            }

            $result = HandlerReflectionCache::invoke(
                $instance,
                $context->requestDto,
                $context->resourceDto,
            );

            if ($result instanceof HttpResponse) {
                throw new PipelineException(sprintf(
                    'Handler %s must return a ResourceInterface, not a HttpResponse object. '
                    . 'Use domain exceptions for errors and resource DTO methods for data.',
                    $instance::class,
                ));
            }

            if (!$result instanceof \Semitexa\Core\Contract\ResourceInterface) {
                throw new PipelineException(sprintf(
                    'Handler %s must return a ResourceInterface, got %s.',
                    $instance::class,
                    gettype($result) . (is_object($result) ? ' (' . $result::class . ')' : '')
                ));
            }

            $context->resourceDto = $result;
            return;
        }

        $instance->handle($context);
    }

    private function executeRouteHandlers(RequestPipelineContext $context): void
    {
        $handlers = $context->route->handlers;
        $sessionId = $this->getSessionIdForAsyncDelivery($context->request);

        foreach ($handlers as $handlerMeta) {
            $handlerClass = $handlerMeta['class'] ?? null;
            if (!$handlerClass) {
                continue;
            }

            $execution = $handlerMeta['execution'] ?? HandlerExecution::Sync->value;
            if ($execution === HandlerExecution::Async->value) {
                QueueDispatcher::enqueue(
                    $handlerMeta,
                    $context->requestDto,
                    $context->resourceDto,
                    $sessionId
                );
                // A mark, not a span: the work happens elsewhere, later.
                $this->tracer?->mark('pipeline.handler.queued', ['handler' => $handlerClass]);
                continue;
            }

            if (!class_exists($handlerClass)) {
                $this->tracer?->mark('pipeline.handler.missing', ['handler' => $handlerClass]);
                continue;
            }

            // Resolution inside the span: a handler whose constructor is the
            // slow part is still charged to that handler, not to the phase.
            $this->span('pipeline.handler', ['handler' => $handlerClass, 'method' => 'handle'], function () use ($handlerClass, $context): void {
                $this->invokeListener($this->resolveHandler($handlerClass), $context);
                $context->lastHandlerClass = $handlerClass;
            });
        }
    }

    private function resolveHandler(string $handlerClass): object
    {
        try {
            return $this->requestScopedContainer->get($handlerClass);
        } catch (\Throwable $e) {
            throw new PipelineException("Failed to resolve handler {$handlerClass}: " . $e->getMessage(), $e);
        }
    }

    /**
     * HandlerCompleted is a domain event dispatched once after the entire pipeline completes.
     * It is NOT a pipeline phase — it is a side-effect for domain listeners (SSE push, analytics).
     */
    private function dispatchHandlerCompleted(RequestPipelineContext $context): void
    {
        // Each way out says WHY nothing fired: "why did no SSE push happen" is
        // the question this span is opened to answer, and a bare false cannot.
        if (!$context->lastHandlerClass) {
            $this->tracer?->mark('pipeline.handler_completed.skipped', ['reason' => 'no_handler_ran']);

            return;
        }

        if (!is_object($context->resourceDto)) {
            $this->tracer?->mark('pipeline.handler_completed.skipped', ['reason' => 'no_resource']);

            return;
        }

        $handle = method_exists($context->resourceDto, 'getRenderHandle')
            ? $context->resourceDto->getRenderHandle()
            : null;

        if (!is_string($handle) || $handle === '') {
            $this->tracer?->mark('pipeline.handler_completed.skipped', ['reason' => 'no_render_handle']);

            return;
        }

        try {
            $events = $this->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            $this->tracer?->mark('pipeline.handler_completed.skipped', ['reason' => 'no_dispatcher']);

            return;
        }

        if (!$events instanceof EventDispatcherInterface) {
            $this->tracer?->mark('pipeline.handler_completed.skipped', ['reason' => 'no_dispatcher']);

            return;
        }

        $sessionId = $this->getSessionIdForAsyncDelivery($context->request);
        $events->dispatch(new HandlerCompleted(
            $context->lastHandlerClass,
            $context->resourceDto,
            $handle,
            $sessionId,
        ));
    }

    private function getSessionIdForAsyncDelivery(\Semitexa\Core\Request $request): string
    {
        $id = $request->getCookie('semitexa_sse_session', '');
        if ($id !== '') {
            return $id;
        }
        $id = $request->getCookie('PHPSESSID', '');
        if ($id !== '') {
            return $id;
        }
        return $request->getQuery('session_id', '');
    }
}
