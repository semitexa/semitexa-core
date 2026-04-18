<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * Core-owned contract for the authentication bootstrapper.
 *
 * Core and downstream consumers (e.g. semitexa-authorization) interact with
 * authentication exclusively through this interface. The concrete
 * implementation lives in semitexa-auth. Core never imports it.
 *
 * Implementations are request-scoped in effect: handle() writes the resolved
 * state into the request-scoped AuthContextInterface and returns the result
 * so callers do not need to reach into a static singleton to read it.
 */
interface AuthBootstrapperInterface
{
    /**
     * Whether authentication is enabled for this worker.
     *
     * Disabled bootstrappers skip handle() entirely; the request proceeds as
     * guest and the caller is responsible for deciding whether that is valid
     * for the current route.
     */
    public function isEnabled(): bool;

    /**
     * Run the configured auth handlers against the given payload and write the
     * outcome into the request-scoped AuthContextInterface.
     *
     * Returns the authentication result, or null when the bootstrapper is
     * disabled or no handler produced a conclusive result. Callers should use
     * the return value rather than re-reading a static singleton.
     *
     * @throws \Throwable Only in Mandatory mode: handler-thrown exceptions propagate.
     */
    public function handle(object $payload, AuthenticationMode $mode = AuthenticationMode::Mandatory): ?AuthResult;
}
