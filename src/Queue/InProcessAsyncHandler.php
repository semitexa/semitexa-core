<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\AfterResponse;
use Semitexa\Core\Support\PayloadSerializer;

/**
 * A payload handler run in the process that served the request, after the
 * response: an Async one always, and a Queued one for a transport no worker
 * reads (without async events the transport is in-memory, one per process,
 * and nothing in that process consumes it).
 *
 * It gets copies of the payload and resource rebuilt from their getters
 * through a constructor without arguments, so it cannot change the response,
 * and the resource it returns goes to the session. The handler is resolved
 * by the caller in the request scope, as an async listener is. An Async one
 * also runs with the request's context: the container's (session, auth,
 * tenant, locale it injects) and what the stores keep beside it
 * ({@see CarriedContext}: the tenant, the signed-in user, the locale and
 * theme templates render in). The work runs after the request has cleared
 * them, or on a re-run tick in a coroutine of its own that never had them.
 * The session was saved with the response, so what it writes there is lost.
 * A Queued one gets neither, as a worker would not, so one that works here
 * works behind a queue too. Inside a request it runs after the response, in
 * the order scheduled; anywhere else, inline. A failure is logged, never
 * thrown. Retries and the dead letter do not apply.
 */
final class InProcessAsyncHandler
{
    /**
     * @param object                 $handler   resolved by the caller while the request scope is live:
     *                                          a deferred callback runs after it has been reset
     * @param SemitexaContainer|null $container whose execution context an Async handler gets
     * @return bool whether it was scheduled; false when it could not be, and that was logged
     */
    public static function schedule(
        object $handler,
        object $payload,
        object $resource,
        string $sessionId,
        HandlerExecution $execution,
        ?SemitexaContainer $container = null,
    ): bool {
        $handlerClass = $handler::class;
        $async = $execution === HandlerExecution::Async;
        $label = $async ? 'async' : 'queued, run in process: no async events';
        try {
            if (!method_exists($handler, 'handle')) {
                throw new \LogicException("handler {$handlerClass} has no handle() method");
            }
            $payloadCopy = self::rebuild($payload);
            $resourceCopy = self::rebuild($resource);
            // Now: the request's context is whole only until the response is out.
            $context = $async ? $container?->captureExecutionContext() : null;
            $carried = $async ? CarriedContext::capture() : null;
        } catch (\Throwable $e) {
            self::logFailure($handlerClass, $label, $e);
            return false;
        }

        $run = static function () use ($handler, $handlerClass, $label, $payloadCopy, $resourceCopy, $sessionId): void {
            try {
                $result = $handler->handle($payloadCopy, $resourceCopy);
            } catch (\Throwable $e) {
                self::logFailure($handlerClass, $label, $e);
                return;
            }
            AsyncResultDeliverer::deliver($sessionId, is_object($result) ? $result : $resourceCopy, $handlerClass);
        };
        if ($carried !== null) {
            $inRequest = $run;
            $run = static function () use ($container, $context, $carried, $inRequest): void {
                CarriedContext::runWith($carried, static function () use ($container, $context, $inRequest): void {
                    if ($container !== null && $context !== null) {
                        $container->runWithExecutionContext($context, $inRequest);
                    } else {
                        $inRequest();
                    }
                });
            };
        }

        // After the response, in order, in flight while it runs; inline
        // outside a request: AfterResponse.
        AfterResponse::schedule($run);

        return true;
    }

    private static function rebuild(object $dto): object
    {
        $class = $dto::class;

        return PayloadSerializer::hydrate(new $class(), PayloadSerializer::toArray($dto));
    }

    private static function logFailure(string $handlerClass, string $label, \Throwable $e): void
    {
        StaticLoggerBridge::error('core', 'Async handler failed', [
            'handler' => $handlerClass,
            'execution' => $label,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
