<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Server\SwooleBootstrap;
use Semitexa\Core\Support\PayloadSerializer;

/**
 * An async payload handler run in the process that served the request, for a
 * transport no worker reads. Without async events the transport is in-memory,
 * one per process, and nothing in that process consumes it: the handler's
 * message was published there and the handler never ran.
 *
 * It gets what a worker would: copies of the payload and resource rebuilt
 * from their getters through a constructor without arguments, so it cannot
 * change the response, and the resource it returns goes to the session the
 * same way. Unlike a worker's, the handler is resolved in the request scope,
 * as an async listener is. Inside a request it runs after the response, in
 * the order scheduled; anywhere else, inline. A failure is logged, never
 * thrown. Retries and the dead letter do not apply.
 */
final class InProcessAsyncHandler
{
    private const DEFERRED_KEY = 'semitexa.queue.deferredAsyncHandlers';

    /**
     * @param object $handler resolved by the caller while the request scope is live:
     *                        a deferred callback runs after it has been reset
     * @return bool whether it was scheduled; false when it could not be, and that was logged
     */
    public static function schedule(object $handler, object $payload, object $resource, string $sessionId): bool
    {
        $handlerClass = $handler::class;
        try {
            if (!method_exists($handler, 'handle')) {
                throw new \LogicException("handler {$handlerClass} has no handle() method");
            }
            $payloadCopy = self::rebuild($payload);
            $resourceCopy = self::rebuild($resource);
        } catch (\Throwable $e) {
            self::logFailure($handlerClass, $e);
            return false;
        }

        $run = static function () use ($handler, $handlerClass, $payloadCopy, $resourceCopy, $sessionId): void {
            try {
                $result = $handler->handle($payloadCopy, $resourceCopy);
            } catch (\Throwable $e) {
                self::logFailure($handlerClass, $e);
                return;
            }
            AsyncResultDeliverer::deliver($sessionId, is_object($result) ? $result : $resourceCopy, $handlerClass);
        };

        // Not merely "inside a coroutine": a standing one (a consumer loop)
        // never exits, so its deferred callbacks would never run.
        if (!SwooleBootstrap::isRequestCoroutine()) {
            $run();
            return true;
        }
        $context = \Swoole\Coroutine::getContext();
        if (!$context instanceof \ArrayObject) {
            $run();
            return true;
        }

        // Coroutine::defer runs LIFO; one deferred callback drains a FIFO
        // queue, so two async handlers of a route run in their declared order.
        $queue = $context[self::DEFERRED_KEY] ?? null;
        if ($queue instanceof \ArrayObject) {
            $queue[] = $run;
            return true;
        }
        $queue = new \ArrayObject([$run]);
        $context[self::DEFERRED_KEY] = $queue;
        \Swoole\Coroutine::defer(static function () use ($queue): void {
            for ($i = 0; $i < count($queue); $i++) {
                $queue[$i]();
            }
        });

        return true;
    }

    private static function rebuild(object $dto): object
    {
        $class = $dto::class;

        return PayloadSerializer::hydrate(new $class(), PayloadSerializer::toArray($dto));
    }

    private static function logFailure(string $handlerClass, \Throwable $e): void
    {
        StaticLoggerBridge::error('core', 'Async handler failed', [
            'handler' => $handlerClass,
            'execution' => 'async, run in process: no async events',
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
