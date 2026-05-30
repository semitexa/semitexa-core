<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline\ReRun;

/**
 * The core re-run unit (Track R · R2) — the heart of Shape 1, "re-run
 * self-authorization".
 *
 * A live SSE stream is a *frozen authorized request*. On each tick/push the owning
 * worker re-runs the FULL handler chain **auth-first**, re-querying data under the
 * recipient's *current* authorization, and either emits a freshly-resolved frame or
 * TERMINATEs if the subject lost access. This contract is that re-run mechanism,
 * isolated from any transport: it is provable headless, with no HTTP, no kiss loop,
 * and no connect coordinator (those are R4/R5).
 *
 * Implementations re-establish the execution context from the {@see ReRunContext}'s
 * immutable block + session reference (NOT from the cached DTO — the anti-poisoning
 * invariant), then delegate to the core route re-executor.
 */
interface ReRunnerInterface
{
    /**
     * Re-run the frozen authorized request described by $context.
     *
     * @return ReRunResult a fresh frame when the subject is still authorized, or a
     *                     TERMINATE signal when it is not (no data frame is produced
     *                     for a de-authorized subject).
     */
    public function reRun(ReRunContext $context): ReRunResult;
}
