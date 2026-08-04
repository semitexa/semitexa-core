<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

/**
 * Optional observer of the request path, for development tooling.
 *
 * {@see RouteExecutor} resolves this from the container the same way it resolves
 * {@see PreHydrationAuthGateInterface}: only if something registered it. Nothing
 * registers it in production, so the cost there is one `has()` per request and
 * the framework behaves exactly as it did before this interface existed. That is
 * deliberate — a dev-only feature gated by a runtime flag is a flag somebody
 * eventually forgets to check, whereas an unregistered service cannot run.
 *
 * ## What it is for
 *
 * Most of the request path is invisible from the pipeline: hydration, response
 * DTO resolution and rendering all happen in RouteExecutor, outside any listener
 * seam. A profiler could time them, but would report function names. This
 * reports what the *framework* was doing — hydration, render — so a developer can
 * see how a request moved through the architecture rather than through the call
 * stack.
 *
 * ## Contract
 *
 * Implementations MUST NOT throw. An observer that can break the request it
 * observes is worse than no observer: it would turn a diagnostic tool into a
 * source of the faults being diagnosed. Swallow your own errors.
 *
 * RouteExecutor does not rely on that alone — it holds every tracer inside
 * {@see SafeRequestTracer}, which contains a throw wherever it happens. The rule
 * still stands, because a tracer reached from anywhere else has no such wrapper,
 * and because an implementation that leans on being wrapped will silently stop
 * recording the first time it faults.
 *
 * Implementations MUST NOT alter behaviour. Nothing returned here is consumed.
 *
 * Spans nest by call order: every {@see begin()} is matched by exactly one
 * {@see end()} on the same span, and a span is closed before the next one at the
 * same level opens. This holds on failing requests too, where the code that would
 * have called `end()` was skipped by an exception — SafeRequestTracer closes what
 * the unwind left open, passing `['unfinished' => true]` so a recording can tell
 * a step that completed from one the request died inside.
 */
interface RequestTracerInterface
{
    /**
     * Open a span.
     *
     * @param string               $name    stable identifier for the step, e.g. `hydration`
     * @param array<string, mixed> $context whatever the step can cheaply describe about itself
     */
    public function begin(string $name, array $context = []): void;

    /**
     * Close the most recently opened span.
     *
     * @param array<string, mixed> $context facts only known once the step finished
     */
    public function end(string $name, array $context = []): void;

    /**
     * Record a point in time that has no duration — a decision, a branch taken.
     *
     * @param array<string, mixed> $context
     */
    public function mark(string $name, array $context = []): void;
}
