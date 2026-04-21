<?php

declare(strict_types=1);

namespace Semitexa\Core\Csrf;

use Semitexa\Core\Session\Attribute\SessionSegment;

/**
 * Session-scoped CSRF token. Stable for the lifetime of the session; rotated on
 * regenerate(). Paired with a non-HttpOnly XSRF-TOKEN cookie so browser JS can
 * copy it into the X-CSRF-Token header for AJAX requests.
 */
#[SessionSegment('__csrf__')]
final class CsrfToken
{
    protected string $value = '';

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    public static function generate(): self
    {
        $token = new self();
        $token->setValue(bin2hex(random_bytes(32)));

        return $token;
    }
}
