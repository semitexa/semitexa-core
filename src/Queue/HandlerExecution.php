<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * Where a payload handler runs (#[AsPayloadHandler(execution: ...)]). The same
 * three words as a listener's {@see \Semitexa\Core\Event\EventExecution}, with
 * the same meaning.
 */
enum HandlerExecution: string
{
    /** In the request, before the response. */
    case Sync = 'sync';

    /**
     * In the process that served the request, after the response, with the
     * request's context (session, auth, tenant, locale); its resource goes to
     * the session. Again on every re-run of a live region. Not durable: a
     * recycle gives it the grace window, and there are no retries.
     */
    case Async = 'async';

    /**
     * Through a queue, in a worker container: retries and the dead letter
     * apply, the request's context does not (the worker gets copies of the
     * payload and resource). Not on re-runs: one job per request.
     */
    case Queued = 'queued';

    /** Whether normalize() knows the word; anything else is read as Sync. */
    public static function isKnown(HandlerExecution|string|null $value): bool
    {
        return $value === null || $value instanceof self
            || in_array(strtolower($value), ['', 'sync', 'async', 'asynchronous', 'queue', 'queued'], true);
    }

    public static function normalize(HandlerExecution|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return self::Sync;
        }

        $normalized = strtolower((string) $value);

        return match ($normalized) {
            'async', 'asynchronous' => self::Async,
            'queue', 'queued' => self::Queued,
            default => self::Sync,
        };
    }
}
