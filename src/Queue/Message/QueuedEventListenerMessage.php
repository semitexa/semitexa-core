<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue\Message;

use JsonException;

class QueuedEventListenerMessage implements \JsonSerializable
{
    public const TYPE = 'event';

    public function __construct(
        public string $listenerClass,
        public string $eventClass,
        public array $eventPayload,
        public string $queuedAt = '',
        // A failed listener is retried this many times, then dead-lettered
        // (QueueWorker), as a queued handler is. Absent from a message queued
        // before these existed: decoded as no retries.
        public int $attempts = 0,
        public int $maxRetries = 0,
        public int $retryDelay = 0,
        /** When it entered the queue, in ms (again on each retry): see QueuedHandlerMessage. */
        public int $publishedAt = 0,
        /** The same on every retry and in its dead letter: see QueuedHandlerMessage. */
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
            'listenerClass' => $this->listenerClass,
            'eventClass' => $this->eventClass,
            'eventPayload' => $this->eventPayload,
            'queuedAt' => $this->queuedAt,
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
        return new self(
            listenerClass: $data['listenerClass'],
            eventClass: $data['eventClass'],
            eventPayload: $data['eventPayload'] ?? [],
            queuedAt: $data['queuedAt'] ?? date(DATE_ATOM),
            attempts: (int) ($data['attempts'] ?? 0),
            maxRetries: (int) ($data['maxRetries'] ?? 0),
            retryDelay: (int) ($data['retryDelay'] ?? 0),
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
