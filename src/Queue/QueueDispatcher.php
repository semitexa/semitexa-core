<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Queue\Message\QueuedHandlerMessage;
use Semitexa\Core\Support\PayloadSerializer;

class QueueDispatcher
{
    /**
     * The transport an async handler's message goes to: its own, else the default.
     *
     * @param array<array-key, mixed> $handlerMeta
     */
    public static function transportFor(array $handlerMeta): string
    {
        $transportName = $handlerMeta['transport'] ?? null;

        return is_string($transportName) && $transportName !== '' ? $transportName : QueueConfig::defaultTransport();
    }

    public static function enqueue(array $handlerMeta, object $requestDto, object $responseDto, string $sessionId = ''): void
    {
        $transportName = self::transportFor($handlerMeta);

        $queueName = $handlerMeta['queue'] ?? null;
        if (!$queueName) {
            $queueName = QueueConfig::defaultQueueName($handlerMeta['payload'] ?? get_class($requestDto));
        }

        $message = new QueuedHandlerMessage(
            handlerClass: $handlerMeta['class'],
            requestClass: get_class($requestDto),
            responseClass: get_class($responseDto),
            requestPayload: PayloadSerializer::toArray($requestDto),
            responsePayload: PayloadSerializer::toArray($responseDto),
            sessionId: $sessionId,
            maxRetries: $handlerMeta['maxRetries'] ?? 0,
            retryDelay: $handlerMeta['retryDelay'] ?? 0,
            origin: QueuedOrigin::capture(),
        );

        $transport = QueueTransportRegistry::create($transportName);
        $transport->publish($queueName, $message->toJson());

        // Record to Inspector
        if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() >= 0) {
            $context = \Swoole\Coroutine::getContext();
            if ($context) {
                if (!isset($context['inspector_segments'])) {
                    $context['inspector_segments'] = [];
                }
                $context['inspector_segments'][] = [
                    'type' => 'queue',
                    'timestamp' => microtime(true),
                    'payload' => [
                        'queue' => $queueName,
                        'transport' => $transportName,
                        'handler' => $handlerMeta['class'],
                        'request' => get_class($requestDto),
                    ]
                ];
            }
        }

    }
}

