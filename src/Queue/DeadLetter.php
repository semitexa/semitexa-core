<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/** One message a queue gave up on: what it was for, why it failed, and its id in the transport. */
final readonly class DeadLetter
{
    public function __construct(
        /** The transport's own id (a row id, a stream sequence): what retry and forget take. */
        public string $id,
        /** The queue it failed on (not the `.failed` queue it now sits in). */
        public string $queue,
        /** The handler or listener class it was for; '' for a payload that could not be read. */
        public string $target,
        public int $attempts,
        public string $error,
        public ?\DateTimeImmutable $failedAt,
        /** The message as it was queued; may hold request data — print it only when asked. */
        public string $payload,
        /** False for a payload that could not be decoded: it can only be forgotten. */
        public bool $retryable,
    ) {}

    /** Read a dead letter from what QueueWorker wrote to `<queue>.failed`. */
    public static function fromStored(string $id, string $queue, string $stored): self
    {
        $data = json_decode($stored, true);
        if (!is_array($data)) {
            return new self($id, $queue, '', 0, 'unreadable', null, $stored, false);
        }
        if (isset($data['raw'])) {
            // What QueueWorker wrote for a message it could not decode: the
            // raw text, why, and when. A non-string error is no reason to print.
            $error = $data['error'] ?? '';

            return new self($id, $queue, '', 0, is_string($error) ? $error : '', self::time($data['failed_at'] ?? null), $stored, false);
        }

        $target = $data['listenerClass'] ?? $data['handler'] ?? $data['handlerClass'] ?? '';

        return new self(
            $id,
            $queue,
            is_string($target) ? $target : '',
            (int) ($data['attempts'] ?? 0),
            (string) ($data['error'] ?? ''),
            self::time($data['failed_at'] ?? null),
            $stored,
            true,
        );
    }

    /**
     * The message to queue again: attempts start over, the failure goes, and it
     * enters the queue now.
     */
    public function retryPayload(): string
    {
        $data = json_decode($this->payload, true);
        if (!$this->retryable || !is_array($data)) {
            throw new \LogicException("Dead letter {$this->id} cannot be retried: its payload was never readable.");
        }
        unset($data['error'], $data['failed_at']);
        $data['attempts'] = 0;
        $data['publishedAt'] = (int) floor(microtime(true) * 1000);

        return (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
