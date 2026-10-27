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
        /**
         * When it entered the queue, in ms — again on each retry, so the
         * time a job waited is start minus this, not minus the first attempt.
         */
        public int $publishedAt = 0,
        /**
         * The same on every retry and in its dead letter: one job could not be
         * followed from attempt to attempt to the letter, in logs or queue:failed.
         */
        public string $messageId = '',
    ) {
        $this->queuedAt = $queuedAt ?: date(DATE_ATOM);
        $this->publishedAt = $publishedAt ?: self::nowMs();
        $this->messageId = $messageId !== '' ? $messageId : 'm-' . bin2hex(random_bytes(8));
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
            'publishedAt' => $this->publishedAt,
            'messageId' => $this->messageId,
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
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Queued handler message is not a JSON object.');
        }
        // Support legacy messages without type
        $handlerClass = $data['handlerClass'] ?? $data['handler'] ?? '';
        // A message without its request or response class cannot be run;
        // say which key is missing instead of a warning and a TypeError.
        foreach (['requestClass', 'responseClass'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Queued handler message for "%s" has no %s.',
                    is_string($handlerClass) ? $handlerClass : get_debug_type($handlerClass),
                    $key,
                ));
            }
        }

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
            publishedAt: self::publishedAtOf($data),
            messageId: is_string($data['messageId'] ?? null) ? $data['messageId'] : '',
        );
    }

    /** Now, in milliseconds: when a message enters a queue. */
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** A message queued before publishedAt existed: its queuedAt, to the second. */
    private static function publishedAtOf(array $data): int
    {
        if (is_numeric($data['publishedAt'] ?? null)) {
            return (int) $data['publishedAt'];
        }
        $queuedAt = is_string($data['queuedAt'] ?? null) ? strtotime($data['queuedAt']) : false;

        return $queuedAt !== false ? $queuedAt * 1000 : self::nowMs();
    }

}

