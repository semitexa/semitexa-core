<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

use Semitexa\Core\Auth\AuthBootstrapperInterface;
use Semitexa\Core\Request;

/**
 * Pre-hydration authentication gate.
 *
 * Runs between bare payload instantiation and payload hydration/validation,
 * so a non-public route can reject an unauthenticated request before any
 * request body is parsed or validated. Prevents unauthenticated clients from
 * forcing hydration work on protected routes.
 *
 * Implementations live in authorization layers (e.g. semitexa-authorization).
 * Core looks the gate up through the container; when absent, RouteExecutor
 * falls back to the legacy order (hydration first, AuthorizationListener
 * enforces in the AuthCheck pipeline phase).
 *
 * @throws \Semitexa\Core\Pipeline\Exception\AuthenticationRequiredException when a protected route has no resolvable subject
 */
interface PreHydrationAuthGateInterface
{
    /**
     * Evaluate the gate for this route before hydration runs.
     *
     * The bare payload instance carries only its class identity (no request
     * data), which is sufficient to resolve access policy attributes and run
     * the auth bootstrapper against request cookies/headers.
     */
    public function gate(object $barePayload, Request $request, ?AuthBootstrapperInterface $authBootstrapper): void;
}
