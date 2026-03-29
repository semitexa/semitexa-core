<?php

declare(strict_types=1);

namespace Semitexa\Core\Event;

/**
 * How an event is processed by default (declared on the event class via #[AsEvent(execution: ...)]).
 * Listener can optionally override via #[AsEventListener(..., execution: ...)].
 */
enum EventExecution: string
{
    /** Run listener immediately in the same request */
    case Sync = 'sync';

    /** Run listener after response is sent, in the same Swoole worker (defer) */
    case Async = 'async';

    /** Enqueue to queue; a separate worker processes when it can */
    case Queued = 'queued';

    public static function normalize(EventExecution|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value === null || $value === '') {
            return self::Sync;
        }
        $v = strtolower((string) $value);
        return match ($v) {
            'async', 'defer', 'swoole' => self::Async,
            'queued', 'queue' => self::Queued,
            default => self::Sync,
        };
    }

    public static function fromAttributeValue(string $value): self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'sync' => self::Sync,
            'async', 'defer', 'swoole' => self::Async,
            'queued', 'queue' => self::Queued,
            default => throw new \InvalidArgumentException("Unknown event execution mode: {$value}"),
        };
    }
}
