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
 */
final class PipelineExecutor
{
    public function __construct(
        private readonly ContainerInterface $requestScopedContainer,
        private readonly ContainerInterface $container,
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
            $this->dispatchPhase($phaseClass, $context);
        }

        $this->dispatchHandlerCompleted($context);
    }

    private function dispatchPhase(string $phaseClass, RequestPipelineContext $context): void
    {
        /** @var PipelineListenerRegistry $registry */
        $registry = $this->container->get(PipelineListenerRegistry::class);
        $listeners = $registry->getListeners($phaseClass);
        foreach ($listeners as $meta) {
            $instance = $this->requestScopedContainer->get($meta['class']);
            $this->invokeListener($instance, $context);
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
                    gettype($result) . (is_object($result) ? ' (' . $instance::class . ')' : '')
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
            $handlerClass = is_array($handlerMeta) ? ($handlerMeta['class'] ?? null) : $handlerMeta;
            if (!$handlerClass) {
                continue;
            }

            $execution = $handlerMeta['execution'] ?? HandlerExecution::Sync->value;
            if ($execution === HandlerExecution::Async->value) {
                QueueDispatcher::enqueue(
                    is_array($handlerMeta) ? $handlerMeta : ['class' => $handlerClass, 'payload' => $context->route->requestClass],
                    $context->requestDto,
                    $context->resourceDto,
                    $sessionId
                );
                continue;
            }

            if (!class_exists($handlerClass)) {
                continue;
            }

            try {
                $handler = $this->requestScopedContainer->get($handlerClass);
            } catch (\Throwable $e) {
                throw new PipelineException("Failed to resolve handler {$handlerClass}: " . $e->getMessage(), $e);
            }

            $this->invokeListener($handler, $context);
            $context->lastHandlerClass = $handlerClass;
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
