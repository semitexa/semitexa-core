<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

/**
 * Enforces the one rule {@see RequestTracerInterface} can only ask for.
 *
 * The interface says implementations must not throw, and an interface cannot make
 * that true. Every call from {@see RouteExecutor} sits on the request path: the
 * root `begin()` runs before the `try` block that maps exceptions, the closing
 * `end()` runs inside `finally` where a throw would replace the response or the
 * application exception on its way out, and the calls in between would be caught
 * and mapped as if the application had failed. A tracer that can do any of that is
 * a diagnostic tool acting as a source of the faults being diagnosed.
 *
 * So the executor never holds a raw tracer. It holds this, which cannot throw
 * whatever it wraps.
 *
 * After the first failure it stops calling through for the rest of the request.
 * A tracer that threw once — a full disk, a broken connection — will almost
 * certainly throw on every remaining span, and swallowing twenty exceptions costs
 * more than the trace is worth. The scope is one request: this wrapper is built
 * per request, so a worker is never left with tracing switched off.
 *
 * ## Balancing spans on the way out
 *
 * The other thing the interface promises and cannot enforce is that every
 * `begin()` is matched by exactly one `end()`. On the request path that promise
 * breaks the moment anything throws: the auth gate, hydration, the pipeline and
 * the renderer each open a span before doing work, and an exception skips the
 * `end()` that follows. The root span then closes in `finally` on top of an open
 * child, leaving an implementation's span stack unbalanced.
 *
 * Rather than five `try`/`finally` blocks at the call sites, this closes the
 * unfinished children itself when an outer span ends, marking each one
 * `unfinished` — so the contract holds for every implementation, and the trace
 * still says which step the request died inside instead of quietly reporting a
 * duration for work that never completed.
 */
final class SafeRequestTracer implements RequestTracerInterface
{
    private bool $broken = false;

    /**
     * Set once the inner tracer says it is recording nothing.
     *
     * An untraced request in dev still walked the whole chain per span, and the
     * pipeline-span work made that chain run about thirty-four times a page.
     * MEASURED here: 44.7 us of tracer traffic on a request that records
     * nothing — four times the estimate this was filed against, and a floor,
     * since it was taken on the path where the coroutine is never consulted.
     *
     * Spans that can OPEN a recording are still passed through, so this cannot
     * silence a trace that has not started yet — which is the normal case for
     * SSE, whose span begins after the surrounding request already declined.
     */
    private bool $silent = false;

    /** @var list<string> Names of spans opened and not yet closed, outermost first. */
    private array $stack = [];

    private function __construct(
        private readonly RequestTracerInterface $inner,
    ) {
    }

    /**
     * Null in, null out — the caller's `?->` stays the null check it was, and a
     * request with no tracer registered pays nothing for this class existing.
     */
    public static function wrap(?RequestTracerInterface $tracer): ?RequestTracerInterface
    {
        if ($tracer === null) {
            return null;
        }

        // Wrapping a wrapper would add a second failure latch and no safety.
        return $tracer instanceof self ? $tracer : new self($tracer);
    }

    public function begin(string $name, array $context = []): void
    {
        if ($this->broken) {
            return;
        }

        // The stack is kept even while silent: end() unwinds against it, and a
        // recording opened later by a nested span must find the nesting intact.
        if ($this->silent && !$this->wantedWhileSilent($name)) {
            $this->stack[] = $name;

            return;
        }

        try {
            $this->inner->begin($name, $context);
            $this->stack[] = $name;
            $this->refreshSilence();
        } catch (\Throwable) {
            $this->fail();
        }
    }

    public function end(string $name, array $context = []): void
    {
        if ($this->broken) {
            return;
        }

        // A span the tracer wants while silent was opened through the inner
        // tracer, so its close has to reach it too — otherwise a journal process
        // is opened and never closed.
        if ($this->silent && !$this->wantedWhileSilent($name)) {
            $this->popTo($name);

            return;
        }

        try {
            $this->closeUnfinishedAbove($name);
            $this->inner->end($name, $context);
        } catch (\Throwable) {
            $this->fail();
        }
    }

    public function mark(string $name, array $context = []): void
    {
        if ($this->broken || $this->silent) {
            return;
        }

        try {
            $this->inner->mark($name, $context);
        } catch (\Throwable) {
            $this->fail();
        }
    }

    /**
     * Ask the inner tracer whether anything is being recorded, after every span
     * that reached it. Re-asked rather than latched once: a span can open a
     * recording at any depth, and a tracer that started recording must not stay
     * silenced by an earlier answer.
     */
    private function refreshSilence(): void
    {
        $this->silent = $this->inner instanceof RecordingAwareTracerInterface
            && !$this->inner->isRecording();
    }

    private function wantedWhileSilent(string $name): bool
    {
        return $this->inner instanceof RecordingAwareTracerInterface
            && $this->inner->wantsWhileSilent($name);
    }

    /**
     * Unwind the stack to the innermost span with this name, without telling the
     * inner tracer: while silent it was never told they opened.
     */
    private function popTo(string $name): void
    {
        $matches = array_keys($this->stack, $name, true);
        if ($matches === []) {
            return;
        }

        array_splice($this->stack, (int) end($matches));
    }

    private function fail(): void
    {
        // Deliberately silent. There is nowhere to report this that is not
        // itself part of the request being observed, and the whole point is
        // that the request does not notice.
        $this->broken = true;
    }

    /**
     * Close every span opened after `$name` and never closed.
     *
     * An `end()` for a span that was never opened is passed through untouched
     * rather than guessed at: the caller knows something this wrapper does not,
     * and inventing a stack entry for it would corrupt the nesting it is here to
     * protect.
     */
    private function closeUnfinishedAbove(string $name): void
    {
        // Innermost match, not the first: a span name can legitimately nest inside
        // itself, and closing the outer one would discard the inner span's work.
        $matches = array_keys($this->stack, $name, true);
        if ($matches === []) {
            return;
        }
        $at = (int) end($matches);

        while (count($this->stack) > $at + 1) {
            $unfinished = array_pop($this->stack);
            $this->inner->end((string) $unfinished, ['unfinished' => true]);
        }

        array_pop($this->stack);
    }

}
