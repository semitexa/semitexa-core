<?php

declare(strict_types=1);

namespace Semitexa\Core\Csrf;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Csrf\Attribute\CsrfExempt;
use Semitexa\Core\Environment;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Session\SessionInterface;

/**
 * Double-submit CSRF validator for state-changing browser requests.
 *
 * Enforces on unsafe HTTP methods (POST/PUT/PATCH/DELETE) whenever the request
 * presents the session cookie, signed in or not. A guest session holds state
 * too (a cart, a draft, a locale, the login itself), and a cross-site form
 * posting into it is the same attack: until bench-anonymous-post-csrf a guest
 * POST skipped the check, so a guest form without csrf_field() worked in
 * development and the same form 403'd the moment its author signed in.
 * Every session carries a token from its first request (SessionPhase), so a
 * guest page can present it like any other.
 *
 * Out of scope: a request with no session cookie (machine-auth API calls, a
 * first request that has no session to forge into), and payloads that declare
 * #[CsrfExempt] (e.g. webhook endpoints).
 *
 * The token lives on the session as CsrfToken. The matching value is sent over
 * the wire either as:
 *   - the X-CSRF-Token request header, or
 *   - the _csrf form field (falling back to POST body).
 *
 * The XSRF-TOKEN cookie is transport-only for browser JS and must be echoed
 * back via one of the channels above; it is not accepted as proof on its own.
 *
 * Closes finding S-5 in the audit: before this listener, no CSRF protection
 * existed — SameSite=Lax was the only cross-site mitigation.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: 10)]
final class CsrfListener implements PipelineListenerInterface
{
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const HEADER_NAME = 'X-CSRF-Token';
    private const FORM_FIELD = CsrfField::NAME;
    #[InjectAsMutable]
    protected SessionInterface $session;

    public function handle(RequestPipelineContext $context): void
    {
        $method = strtoupper($context->request->getMethod());
        if (!in_array($method, self::UNSAFE_METHODS, true)) {
            return;
        }

        // CSRF is a session-cookie attack: it applies whenever the request
        // presents the session cookie the browser flow uses, guest or not.
        if (!$this->hasSessionCookie($context)) {
            return;
        }

        // Per-payload opt-out for webhook / machine-auth routes.
        $payloadRef = new \ReflectionClass($context->requestDto);
        if ($payloadRef->getAttributes(CsrfExempt::class) !== []) {
            return;
        }

        if (!isset($this->session)) {
            throw new AccessDeniedException('CSRF validation failed: no session.');
        }

        /** @var CsrfToken $token */
        $token = $this->session->getPayload(CsrfToken::class);
        $expected = $token->getValue();
        if ($expected === '') {
            throw new AccessDeniedException('CSRF validation failed: no token in session.');
        }

        $submitted = $this->extractSubmittedToken($context);
        if ($submitted === '' || !hash_equals($expected, $submitted)) {
            throw new AccessDeniedException('CSRF validation failed.');
        }
    }

    private function extractSubmittedToken(RequestPipelineContext $context): string
    {
        $request = $context->request;

        $header = $request->getHeader(self::HEADER_NAME);
        if (is_string($header) && $header !== '') {
            return trim($header);
        }

        $post = $request->post[self::FORM_FIELD] ?? null;
        if (is_string($post) && $post !== '') {
            return trim($post);
        }

        return '';
    }

    private function hasSessionCookie(RequestPipelineContext $context): bool
    {
        $cookieName = Environment::getEnvValue('SESSION_COOKIE_NAME') ?? 'semitexa_session';

        return trim($context->request->getCookie($cookieName, '')) !== '';
    }
}
