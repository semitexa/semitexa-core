<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * Plain values a queued job takes from the request that queued it, for what
 * it hands back to that request's visitor: the result a Queued handler
 * delivers renders in the worker, and a signed UI context minted there was
 * bound to no session, so any session could present it.
 *
 * A store names what it contributes once per worker ({@see exporter()}); core
 * knows none of them. {@see QueueDispatcher::enqueue()} writes {@see capture()}
 * into the message, and the worker (or an in-process Queued run) runs the job
 * inside it ({@see run()}), where the store reads it back ({@see get()}).
 * Scalars and arrays only: it travels in the message, so nothing secret goes
 * in (a digest, not a session id).
 *
 * It is not the request's context: a Queued handler still runs with no
 * session, signed-in user or tenant.
 */
final class QueuedOrigin
{
    private const CTX_KEY = 'core.queued_origin';

    /** @var array<string, \Closure(): ?array<string, scalar>> */
    #[WorkerState('Exporters named once per worker by the stores that own them; the values are per job.')]
    private static array $exporters = [];

    /**
     * Idempotent: a store may name its exporter on every request.
     *
     * @param \Closure(): ?array<string, scalar> $export what this request contributes, or null
     */
    public static function exporter(string $name, \Closure $export): void
    {
        self::$exporters[$name] ??= $export;
    }

    /** @return array<string, array<string, scalar>> what each exporter gives for the running request */
    public static function capture(): array
    {
        $origin = [];
        foreach (self::$exporters as $name => $export) {
            // One store's failure must not stop every job from being queued.
            try {
                $values = $export();
            } catch (\Throwable $e) {
                StaticLoggerBridge::warning('queue', 'A queued job origin could not be captured; the job goes without it.', ['exporter' => $name, 'error' => $e->getMessage()]);
                continue;
            }
            if ($values !== null) {
                $origin[$name] = $values;
            }
        }

        return $origin;
    }

    /**
     * Runs $work with $origin readable through {@see get()}, then puts back
     * what was there.
     *
     * @template T
     * @param array<array-key, mixed> $origin from {@see capture()}, as the message carried it
     * @param \Closure(): T $work
     * @return T
     */
    public static function run(array $origin, \Closure $work): mixed
    {
        $previous = CoroutineLocal::get(self::CTX_KEY, null);
        CoroutineLocal::set(self::CTX_KEY, $origin);
        try {
            return $work();
        } finally {
            $previous === null ? CoroutineLocal::remove(self::CTX_KEY) : CoroutineLocal::set(self::CTX_KEY, $previous);
        }
    }

    /** @return array<array-key, mixed>|null what $name exported for the job running now */
    public static function get(string $name): ?array
    {
        $origin = CoroutineLocal::get(self::CTX_KEY, null);
        $values = is_array($origin) ? ($origin[$name] ?? null) : null;

        return is_array($values) ? $values : null;
    }
}
