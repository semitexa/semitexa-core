<?php

declare(strict_types=1);

namespace Semitexa\Core\Csrf\Attribute;

use Attribute;

/**
 * Opts a payload out of CSRF validation.
 *
 * Use for endpoints that authenticate via their own scheme and cannot rely on a
 * browser session: inbound webhooks (HMAC-signed), machine-auth APIs (Bearer
 * tokens), and public read-only endpoints that use no credentials.
 *
 * Do NOT apply to session-authenticated state-changing endpoints — that is
 * exactly what the CsrfListener protects.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CsrfExempt
{
}
