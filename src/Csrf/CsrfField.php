<?php

declare(strict_types=1);

namespace Semitexa\Core\Csrf;

use Semitexa\Core\Session\SessionInterface;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * The CSRF token of the request being served, for a plain HTML form:
 * `{{ csrf_field() }}` instead of a handler reading CsrfToken from the session
 * and threading it through its resource into the template.
 *
 * The SESSION is bound per coroutine, not the token's value: signing in
 * regenerates the session and rotates the token, and a page rendered after
 * that in the same request must carry the new one. With no request (CLI, a
 * test) there is no token and the field renders nothing.
 */
final class CsrfField
{
    /** The form field CsrfListener reads (the header is X-CSRF-Token). */
    public const NAME = '_csrf';

    private const KEY = 'core.csrf_session';

    /** Bind the session of the request being served on this coroutine. */
    public static function bind(SessionInterface $session): void
    {
        CoroutineLocal::set(self::KEY, $session);
    }

    public static function clear(): void
    {
        CoroutineLocal::remove(self::KEY);
    }

    /** This request's token, or '' outside a request. */
    public static function token(): string
    {
        $session = CoroutineLocal::get(self::KEY);
        if (!$session instanceof SessionInterface) {
            return '';
        }
        return $session->getPayload(CsrfToken::class)->getValue();
    }

    /** `<input type="hidden" name="_csrf" value="…">`, or '' outside a request. */
    public static function hiddenInput(): string
    {
        $token = self::token();

        return $token === ''
            ? ''
            : '<input type="hidden" name="' . self::NAME . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
