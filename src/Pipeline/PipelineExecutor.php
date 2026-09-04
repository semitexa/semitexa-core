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
 * Spans are closed in `finally`: a listener that throws still leaves a trace
 * that shows which listener it was.
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
     * @return list<class-string>
     */
    protected function getPhases(): array
    {
        return [AuthCheck::class, HandleRequest::class];
    }

    public function execute(RequestPipelineContext $context): void
    {
        foreach ($this->getPhases() as $phaseClass) {
            // pipeline.auth_check, pipeline.handle_request: the phase reads as a
            // step in the viewer, and the FQCN rides in the context for the link.
            $span = 'pipeline.' . self::snake(self::short($phaseClass));
            $this->tracer?->begin($span, ['phase' => $phaseClass]);
            try {
                $this->dispatchPhase($phaseClass, $context);
            } finally {
                $this->tracer?->end($span);
            }
        }

        $this->tracer?->begin('pipeline.handler_completed');
        try {
            $this->dispatchHandlerCompleted($context);
        } finally {
            $this->tracer?->end('pipeline.handler_completed', ['dispatched' => $context->handlerCompletedDispatched]);
        }
    }

    private function dispatchPhase(string $phaseClass, RequestPipelineContext $context): void
    {
        /** @var PipelineListenerRegistry $registry */
        $registry = $this->container->get(PipelineListenerRegistry::class);
        $listeners = $registry->getListeners($phaseClass);
        foreach ($listeners as $meta) {
            $instance = $this->requestScopedContainer->get($meta['class']);
            // The listener is entered through handle() by contract
            // (PipelineListenerInterface); the span says so for the source link.
            $this->tracer?->begin('pipeline.listener', ['listener' => $meta['class'], 'method' => 'handle']);
            try {
                $this->invokeListener($instance, $context);
            } finally {
                $this->tracer?->end('pipeline.listener');
            }
        }

        if ($phaseClass === HandleRequest::class) {
            $this->executeRouteHandlers($context);
        }
    }

    private static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
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
            $this->tracer?->begin('pipeline.handler', ['handler' => $handlerClass, 'method' => 'handle']);
            try {
                try {
                    $handler = $this->requestScopedContainer->get($handlerClass);
                } catch (\Throwable $e) {
                    throw new PipelineException("Failed to resolve handler {$handlerClass}: " . $e->getMessage(), $e);
                }

                $this->invokeListener($handler, $context);
                $context->lastHandlerClass = $handlerClass;
            } finally {
                $this->tracer?->end('pipeline.handler');
            }
        }
    }

    /**
     * HandlerCompleted is a domain event dispatched once after the entire pipeline completes.
     * It is NOT a pipeline phase — it is a side-effect for domain listeners (SSE push, analytics).
     */
    private function dispatchHandlerCompleted(RequestPipelineContext $context): void
    {
        if (!$context->lastHandlerClass) {
            return;
        }

        if (!is_object($context->resourceDto)) {
            return;
        }

        $handle = method_exists($context->resourceDto, 'getRenderHandle')
            ? $context->resourceDto->getRenderHandle()
            : null;

        if (!is_string($handle) || $handle === '') {
            return;
        }

        try {
            $events = $this->container->get(EventDispatcherInterface::class);
        } catch (\Throwable) {
            return;
        }

        if (!$events instanceof EventDispatcherInterface) {
            return;
        }

        $sessionId = $this->getSessionIdForAsyncDelivery($context->request);
        $context->handlerCompletedDispatched = true;
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
