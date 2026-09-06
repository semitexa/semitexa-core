<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

/**
 * A tracer that can say whether the request in front of it is being recorded.
 *
 * In dev the tracer is registered for every request but records only the ones
 * carrying the marker. Without this, every other request still pays the full
 * call chain per span — and the pipeline-span work took a plain page from about
 * six spans to sixteen, so that chain runs roughly thirty-four times on every
 * asset, poll and page, and on every SSE tick for the life of every untraced
 * connection.
 *
 * MEASURED 2026-09-06 on this repository, sixteen spans per request: 44.7 us of
 * tracer traffic on a request that records nothing. The estimate this was filed
 * against was ~10 us, so the cost is roughly four times what was assumed — and
 * that figure is a floor, taken on the CLI path where TraceContext::current()
 * returns its fallback without touching the coroutine at all.
 *
 * Optional on purpose: {@see RequestTracerInterface} stays a three-method
 * contract that anything can satisfy, and a tracer that does not implement this
 * is simply always called, exactly as before.
 */
interface RecordingAwareTracerInterface
{
    /**
     * Whether a recording is currently open. False means every span handed over
     * from here on is discarded, so the caller may stop handing them over.
     */
    public function isRecording(): bool;

    /**
     * Whether this span must be handed over even while nothing is recorded.
     *
     * Two reasons a caller cannot simply stop talking to a silent tracer, and
     * both were found by breaking them:
     *
     *  - An SSE connection is served inside the route executor, so its span
     *    begins after the surrounding request already declined to record — and
     *    that span is the one that STARTS the SSE trace. Silence it and SSE can
     *    never be traced at all.
     *  - A tracer may do work for a span that has nothing to do with recording.
     *    Semitexa's writes the Observatory journal for request, SSE and queue-job
     *    spans whether or not a trace file is collected; silencing a queue job
     *    left its journal process opened and never closed.
     */
    public function wantsWhileSilent(string $name): bool;
}
