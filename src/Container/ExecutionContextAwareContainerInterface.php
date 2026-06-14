<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

/**
 * A container that tracks an execution (request) scope: it can be told the current
 * {@see ExecutionContext} and can report whether a service id resolves to an
 * execution-scoped prototype.
 *
 * Extracted so {@see RequestScopedContainer} depends on this CAPABILITY rather than
 * the concrete {@see SemitexaContainer}. The previous `instanceof SemitexaContainer`
 * guard was untestable by a fake — a re-run / pipeline unit could never make the
 * execution-context-readiness guard fire, which is exactly why the held-open re-run
 * regression (an execution-scoped listener resolved before SessionPhase established
 * the context) slipped past the R2 unit suite. Depending on this interface lets a
 * test double enforce the guard, so the blind spot cannot reopen.
 */
interface ExecutionContextAwareContainerInterface
{
    /**
     * Apply the request-scoped execution context (Request / Session / CookieJar /
     * Tenant / Auth / Locale) so execution-scoped services resolve with it injected.
     */
    public function setExecutionContext(ExecutionContext $context): void;

    /**
     * Whether the given service id resolves to an execution-scoped prototype (one
     * that may only be resolved once the execution context has been set).
     */
    public function isExecutionScoped(string $id): bool;
}
