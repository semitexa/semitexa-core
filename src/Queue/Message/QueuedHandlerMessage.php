<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue\Message;

use JsonException;

class QueuedHandlerMessage implements \JsonSerializable
{
    public const TYPE = 'handler';

    public function __construct(
        public string $handlerClass,
        public string $requestClass,
        public string $responseClass,
        public array $requestPayload,
        public array $responsePayload,
        public string $queuedAt = '',
        public string $sessionId = '',
        public int $attempts = 0,
        public int $maxRetries = 0,
        public int $retryDelay = 0,
    ) {
        $this->queuedAt = $queuedAt ?: date(DATE_ATOM);
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => self::TYPE,
            'handler' => $this->handlerClass,
            'requestClass' => $this->requestClass,
            'responseClass' => $this->responseClass,
            'requestPayload' => $this->requestPayload,
            'responsePayload' => $this->responsePayload,
            'queuedAt' => $this->queuedAt,
            'sessionId' => $this->sessionId,
            'attempts' => $this->attempts,
            'maxRetries' => $this->maxRetries,
            'retryDelay' => $this->retryDelay,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $payload): self
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        // Support legacy messages without type
        $handlerClass = $data['handlerClass'] ?? $data['handler'] ?? '';

        return new self(
            handlerClass: $handlerClass,
            requestClass: $data['requestClass'],
            responseClass: $data['responseClass'],
            requestPayload: $data['requestPayload'] ?? [],
            responsePayload: $data['responsePayload'] ?? [],
            queuedAt: $data['queuedAt'] ?? date(DATE_ATOM),
            sessionId: $data['sessionId'] ?? '',
            attempts: $data['attempts'] ?? 0,
            maxRetries: $data['maxRetries'] ?? 0,
            retryDelay: $data['retryDelay'] ?? 0,
        );
    }
}

