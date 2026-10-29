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
     * @throws \InvalidArgumentException a message that cannot be an event message, saying why
     */
    public static function fromJson(string $payload): self
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Queued event message is not a JSON object.');
        }
        // Without its listener or event class it cannot be run: say which key
        // is missing instead of a TypeError from the constructor.
        foreach (['listenerClass', 'eventClass'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new \InvalidArgumentException(sprintf('Queued event message has no %s.', $key));
            }
        }
        // json_decode gives a JSON list and a JSON object the same PHP type, and
        // a list hydrates no property: the listener ran on an event of defaults.
        // An empty one is an empty object written by json_encode([]).
        $eventPayload = array_key_exists('eventPayload', $data) ? $data['eventPayload'] : [];
        if (!is_array($eventPayload) || ($eventPayload !== [] && array_is_list($eventPayload))) {
            throw new \InvalidArgumentException('Queued event message has an eventPayload that is not an object.');
        }

        return new self(
            listenerClass: $data['listenerClass'],
            eventClass: $data['eventClass'],
            eventPayload: $eventPayload,
            queuedAt: is_string($data['queuedAt'] ?? null) ? $data['queuedAt'] : date(DATE_ATOM),
            attempts: self::intOf($data['attempts'] ?? 0),
            maxRetries: self::intOf($data['maxRetries'] ?? 0),
            retryDelay: self::intOf($data['retryDelay'] ?? 0),
            publishedAt: self::publishedAtOf($data),
            messageId: is_string($data['messageId'] ?? null) ? $data['messageId'] : '',
        );
    }

    /** A count from JSON: a number or a numeric string; anything else is none. */
    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Now, in milliseconds: when a message enters a queue. */
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * A message queued before publishedAt existed: its queuedAt, to the second.
     *
     * @param array<mixed> $data
     */
    private static function publishedAtOf(array $data): int
    {
        if (is_numeric($data['publishedAt'] ?? null)) {
            return (int) $data['publishedAt'];
        }
        $queuedAt = is_string($data['queuedAt'] ?? null) ? strtotime($data['queuedAt']) : false;

        return $queuedAt !== false ? $queuedAt * 1000 : self::nowMs();
    }

}
