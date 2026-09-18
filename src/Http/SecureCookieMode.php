<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * The three answers `SESSION_COOKIE_SECURE` can give.
 *
 * `Auto` is not "unset": it is the deployment saying "decide from the request",
 * which is the right answer everywhere the scheme is detected correctly and the
 * wrong one behind a proxy nobody trusts. See {@see SecureCookieSetting}.
 */
enum SecureCookieMode
{
    case Always;
    case Never;
    case Auto;
}
