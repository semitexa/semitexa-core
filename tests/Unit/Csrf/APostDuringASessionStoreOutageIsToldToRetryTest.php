<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Csrf;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Csrf\Attribute\CsrfExempt;
use Semitexa\Core\Csrf\CsrfListener;
use Semitexa\Core\Csrf\CsrfToken;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Discovery\ResolvedRouteMetadata;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Exception\ServiceUnavailableException;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\ExceptionMapper;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;
use Semitexa\Core\Session\Session;
use Semitexa\Core\Session\SessionHandlerInterface;
use Semitexa\Core\Session\TransientSessionHandler;

/**
 * tk-post-during-redis-outage-fails-csrf-not-retryable: while the session store
 * is down, SessionPhase serves a transient session with a token of its own, so a
 * POST carrying the page's token was refused as a forgery, 403, for an outage.
 * It is told to retry: 503 with Retry-After.
 */
final class APostDuringASessionStoreOutageIsToldToRetryTest extends TestCase
{
    private const TOKEN = 'the-token-the-page-carries';

    #[Test]
    public function a_post_on_a_session_the_store_could_not_read_is_told_to_retry(): void
    {
        $session = new Session('session-id', new TransientSessionHandler(), 'semitexa_session');
        self::assertFalse($session->persists());

        try {
            $this->check($session, submitted: self::TOKEN);
            self::fail('a POST on a transient session is not served');
        } catch (ServiceUnavailableException $e) {
            self::assertSame(503, $e->getStatusCode()->value);
            self::assertSame(['Retry-After' => '1'], $e->headers());
            self::assertSame('Temporarily unavailable, try again in a moment.', $e->getMessage());
        }
    }

    #[Test]
    public function the_error_response_is_a_503_with_retry_after(): void
    {
        $response = (new ExceptionMapper())->map(
            new ServiceUnavailableException(),
            new Request('POST', '/profile', ['Accept' => 'application/json'], [], [], [], []),
            $this->metadata(),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaders()['Retry-After'] ?? null);
        self::assertSame(
            ['error' => 'service_unavailable', 'message' => 'Temporarily unavailable, try again in a moment.', 'context' => ['retry_after' => 1]],
            json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** The mapper now asks for the headers an exception carries; a 429 keeps its own. */
    #[Test]
    public function a_429_keeps_its_rate_limit_headers(): void
    {
        $response = (new ExceptionMapper())->map(
            new RateLimitException(30, 100),
            new Request('POST', '/profile', ['Accept' => 'application/json'], [], [], [], []),
            $this->metadata(),
        );

        self::assertSame(429, $response->getStatusCode());
        $headers = $response->getHeaders();
        self::assertSame(['30', '100', '0', '30'], [$headers['Retry-After'] ?? null, $headers['RateLimit-Limit'] ?? null, $headers['RateLimit-Remaining'] ?? null, $headers['RateLimit-Reset'] ?? null]);
    }

    #[Test]
    public function a_session_the_store_keeps_is_checked_for_forgery_as_before(): void
    {
        $session = new Session('session-id', $this->keptStore(), 'semitexa_session');
        $token = new CsrfToken();
        $token->setValue(self::TOKEN);
        $session->setPayload($token);
        self::assertTrue($session->persists());

        $this->check($session, submitted: self::TOKEN);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('CSRF validation failed.');
        $this->check($session, submitted: 'a-forged-token');
    }

    #[Test]
    public function an_exempt_route_is_served_during_the_outage_too(): void
    {
        $session = new Session('session-id', new TransientSessionHandler(), 'semitexa_session');

        $this->check($session, submitted: '', payload: new #[CsrfExempt] class {});

        $this->addToAssertionCount(1);
    }

    private function check(Session $session, string $submitted, ?object $payload = null): void
    {
        $listener = new CsrfListener();
        (new \ReflectionProperty(CsrfListener::class, 'session'))->setValue($listener, $session);

        $listener->handle(new RequestPipelineContext(
            requestDto: $payload ?? new \stdClass(),
            route: new DiscoveredRoute(path: '/profile', methods: ['POST'], name: 'profile.update', requestClass: \stdClass::class, responseClass: null, handlers: [], type: 'http-request', transport: null, produces: null, consumes: null, module: 'core'),
            request: new Request(method: 'POST', uri: '/profile', headers: ['X-CSRF-Token' => $submitted], query: [], post: [], server: [], cookies: ['semitexa_session' => 'session-id']),
        ));
    }

    private function keptStore(): SessionHandlerInterface
    {
        return new class implements SessionHandlerInterface {
            /** @var array<string, array<string, mixed>> */
            private array $sessions = [];

            public function read(string $sessionId): array
            {
                return $this->sessions[$sessionId] ?? [];
            }

            public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
            {
                $this->sessions[$sessionId] = $data;
            }

            public function destroy(string $sessionId): void
            {
                unset($this->sessions[$sessionId]);
            }
        };
    }

    private function metadata(): ResolvedRouteMetadata
    {
        return new ResolvedRouteMetadata(
            path: '/profile',
            name: 'profile.update',
            methods: ['POST'],
            requestClass: 'ProfileRequest',
            responseClass: 'ProfileResponse',
            produces: ['json'],
            consumes: null,
            handlers: [],
            requirements: [],
            extensions: [],
        );
    }
}
