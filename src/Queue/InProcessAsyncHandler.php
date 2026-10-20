<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Lifecycle\RequestContextSnapshot;
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
 * and the resource it returns goes to the session. An Async one is resolved
 * in the request scope, as an async listener is, and runs with the
 * request's context: the container's (session, auth, tenant, locale it
 * injects) and what the stores keep beside it ({@see CarriedContext}: the
 * tenant, the signed-in user, the locale and theme templates render in).
 * The work runs after the request has cleared them, or in a coroutine of
 * its own that never had them. The session is saved with the response, so
 * what it writes there may never be. A Queued one is resolved and run with
 * none of it, as a worker would, even where some context is live (a
 * coroutine that carries its parent's), so one that works here works behind
 * a queue too. Inside a request it runs after the response, in
 * the order scheduled; anywhere else, inline. A failure is logged, never
 * thrown. Retries and the dead letter do not apply.
 */
final class InProcessAsyncHandler
{
    /**
     * @param \Closure(): object      $resolve   the handler. An Async one is resolved now, while the
     *                                          request scope is live (a deferred callback runs after
     *                                          it has been reset); a Queued one when it runs, with no
     *                                          request context, as a worker resolves it
     * @param SemitexaContainer|null $container whose execution context an Async handler gets (and a
     *                                          Queued one is kept from)
     * @return bool whether it was scheduled; false when it could not be, and that was logged
     */
    public static function schedule(
        string $handlerClass,
        \Closure $resolve,
        object $payload,
        object $resource,
        string $sessionId,
        HandlerExecution $execution,
        ?SemitexaContainer $container = null,
    ): bool {
        $async = $execution === HandlerExecution::Async;
        $label = $async ? 'async' : 'queued, run in process: no async events';
        try {
            $payloadCopy = self::rebuild($payload);
            $resourceCopy = self::rebuild($resource);
            $handle = $async ? self::handleOf($resolve, $handlerClass) : null;
            // Now: the request's context is whole only until the response is out.
            // A Queued one runs with none, wherever it runs: as a worker would.
            $context = $async ? RequestContextSnapshot::capture($container) : RequestContextSnapshot::none($container);
        } catch (\Throwable $e) {
            self::logFailure($handlerClass, $label, $e);
            return false;
        }

        $run = static function () use ($handle, $resolve, $handlerClass, $label, $payloadCopy, $resourceCopy, $sessionId, $context): void {
            $context->run(static function () use ($handle, $resolve, $handlerClass, $label, $payloadCopy, $resourceCopy, $sessionId): void {
                try {
                    $result = ($handle ?? self::handleOf($resolve, $handlerClass))($payloadCopy, $resourceCopy);
                } catch (\Throwable $e) {
                    self::logFailure($handlerClass, $label, $e);
                    return;
                }
                AsyncResultDeliverer::deliver($sessionId, is_object($result) ? $result : $resourceCopy, $handlerClass);
            });
        };

        // After the response, in order, in flight while it runs; inline
        // outside a request: AfterResponse.
        AfterResponse::schedule($run);

        return true;
    }

    /**
     * @param \Closure(): object $resolve
     * @return \Closure(object, object): mixed the handler's handle()
     */
    private static function handleOf(\Closure $resolve, string $handlerClass): \Closure
    {
        $handle = [$resolve(), 'handle'];
        if (!is_callable($handle)) {
            throw new \LogicException("handler {$handlerClass} has no handle() method");
        }

        return \Closure::fromCallable($handle);
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
