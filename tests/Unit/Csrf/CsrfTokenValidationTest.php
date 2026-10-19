<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Csrf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Csrf\Attribute\CsrfExempt;
use Semitexa\Core\Csrf\CsrfField;
use Semitexa\Core\Csrf\CsrfListener;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Core\Session\SessionInterface;

/**
 * The token check itself, for a signed-in browser session
 * (tk-parity-proof-gaps): CsrfListenerTest proves only the two ways out
 * before it — no session cookie, no auth context.
 */
final class CsrfTokenValidationTest extends TestCase
{
    private const TOKEN = 'a3f1c9e0b7d24e6f8a1b2c3d4e5f6071';

    #[Test]
    public function a_post_with_the_wrong_token_is_refused(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('CSRF validation failed.');

        $this->check('POST', headers: ['X-CSRF-Token' => 'b' . substr(self::TOKEN, 1)]);
    }

    #[Test]
    public function a_post_with_no_token_is_refused(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->check('POST');
    }

    #[Test]
    public function every_unsafe_method_is_checked(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            try {
                $this->check($method);
                self::fail("{$method} went through without a token");
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_right_token_passes_in_the_header_or_the_form_field(): void
    {
        $this->check('POST', headers: ['X-CSRF-Token' => self::TOKEN]);
        $this->check('POST', post: [CsrfField::NAME => self::TOKEN]);

        $this->addToAssertionCount(2);
    }

    #[Test]
    public function a_session_with_no_token_refuses_whatever_is_submitted(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('no token in session');

        // An empty expected value would otherwise compare equal to an empty submission.
        $this->check('POST', post: [CsrfField::NAME => ''], sessionToken: '');
    }

    #[Test]
    public function reads_and_exempt_payloads_are_not_checked(): void
    {
        $this->check('GET');
        $this->check('POST', payload: new CsrfExemptWebhookPayload());

        $this->addToAssertionCount(2);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $post
     */
    private function check(string $method, array $headers = [], array $post = [], string $sessionToken = self::TOKEN, ?object $payload = null): void
    {
        $token = new CsrfToken();
        $token->setValue($sessionToken);
        $session = $this->createStub(SessionInterface::class);
        $session->method('getPayload')->willReturn($token);
        $auth = $this->createStub(AuthContextInterface::class);
        $auth->method('isGuest')->willReturn(false);

        $listener = new CsrfListener();
        (new \ReflectionProperty(CsrfListener::class, 'session'))->setValue($listener, $session);
        (new \ReflectionProperty(CsrfListener::class, 'authContext'))->setValue($listener, $auth);

        $listener->handle(new RequestPipelineContext(
            requestDto: $payload ?? new \stdClass(),
            route: new DiscoveredRoute(path: '/profile', methods: [$method], name: 'profile.update', requestClass: \stdClass::class, responseClass: null, handlers: [], type: 'http-request', transport: null, produces: null, consumes: null, module: 'core'),
            request: new Request(method: $method, uri: '/profile', headers: $headers, query: [], post: $post, server: [], cookies: ['semitexa_session' => 'session-id']),
        ));
    }
}

#[CsrfExempt]
final class CsrfExemptWebhookPayload {}
