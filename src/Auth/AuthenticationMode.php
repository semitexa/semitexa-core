<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * Controls how the authentication bootstrapper handles credentials.
 *
 * Mandatory:
 *   Used for protected endpoints. Authentication runs normally.
 *   Auth handler exceptions propagate.
 *
 * BestEffort:
 *   Used for public endpoints. Authentication is attempted but any
 *   failure — missing credentials, invalid token, or infrastructure error —
 *   degrades to guest context and is logged (if a logger is wired). The
 *   request is never denied solely because credentials are absent or invalid.
 */
enum AuthenticationMode
{
    case Mandatory;
    case BestEffort;
}
