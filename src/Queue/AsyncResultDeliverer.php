<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Contract\AsyncResultDeliveryInterface;
use Semitexa\Core\Log\FallbackErrorLogger;

/**
 * Hands an async handler's resource to the session that asked for it, wherever
 * the handler ran: in a queue worker, or in process without async events.
 */
final class AsyncResultDeliverer
{
    public static function deliver(string $sessionId, object $responseDto, string $handlerClass = ''): void
    {
        if ($sessionId === '') {
            return;
        }
        try {
            $container = ContainerFactory::get();
            if (!$container->has(AsyncResultDeliveryInterface::class)) {
                // No delivery implementation bound — an intentional, optional
                // configuration (fire-and-forget). Not a failure; stay silent.
                return;
            }
            $delivery = $container->get(AsyncResultDeliveryInterface::class);
            if ($delivery instanceof AsyncResultDeliveryInterface) {
                $delivery->deliver($sessionId, $responseDto, $handlerClass);
            }
        } catch (\Throwable $e) {
            // Reaching here means a BOUND delivery threw (the not-bound case
            // returned above): the client that issued the async command never
            // receives its result and the outcome is silently lost. The
            // caller must still proceed, but the drop must be logged so
            // a broken result channel is detectable.
            FallbackErrorLogger::log('Async result delivery failed', [
                'session' => $sessionId,
                'handler' => $handlerClass !== '' ? $handlerClass : $responseDto::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
