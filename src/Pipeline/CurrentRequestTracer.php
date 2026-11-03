<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * The request's tracer, for the code that runs inside a span it cannot reach:
 * the renderer opens `response.render`, and what ssr does inside it (the
 * deferral setup, the Twig render, finalizing) had no spans, so half of every
 * HTML request read as one opaque block (ai:observe, 2026-11-03).
 *
 * RouteExecutor publishes the wrapped tracer when a request starts. It is null
 * where no tracer is registered, as in production, so a caller's `?->` costs a
 * null check. Per coroutine under Swoole; the CLI store is cleared between
 * requests (CoroutineLocal).
 */
final class CurrentRequestTracer
{
    private const KEY = '__core_current_request_tracer';

    /** Publish this request's tracer (null for none); returns it, so the caller can assign in one step. */
    public static function publish(?RequestTracerInterface $tracer): ?RequestTracerInterface
    {
        if ($tracer === null) {
            CoroutineLocal::remove(self::KEY);
        } else {
            CoroutineLocal::set(self::KEY, $tracer);
        }

        return $tracer;
    }

    public static function get(): ?RequestTracerInterface
    {
        $tracer = CoroutineLocal::get(self::KEY);

        return $tracer instanceof RequestTracerInterface ? $tracer : null;
    }
}
