<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Csrf;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Csrf\CsrfListener;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Pipeline\Exception\AccessDeniedException;
use Semitexa\Core\Pipeline\RequestPipelineContext;
use Semitexa\Core\Request;

final class CsrfListenerTest extends TestCase
{
    public function testUnsafeRequestWithoutSessionCookieSkipsMissingAuthContext(): void
    {
        $listener = new CsrfListener();
        $context = new RequestPipelineContext(
            requestDto: new \stdClass(),
            route: new DiscoveredRoute(
                path: '/api/events',
                methods: ['POST'],
                name: 'api.events',
                requestClass: \stdClass::class,
                responseClass: null,
                handlers: [],
                type: 'http_request',
                transport: null,
                produces: null,
                consumes: null,
                module: 'core',
            ),
            request: new Request(
                method: 'POST',
                uri: '/api/events',
                headers: [],
                query: [],
                post: [],
                server: [],
                cookies: [],
            ),
        );

        $listener->handle($context);

        $this->addToAssertionCount(1);
    }

    public function testUnsafeSessionRequestWithoutAuthContextFailsClosed(): void
    {
        $listener = new CsrfListener();
        $context = new RequestPipelineContext(
            requestDto: new \stdClass(),
            route: new DiscoveredRoute(
                path: '/profile',
                methods: ['POST'],
                name: 'profile.update',
                requestClass: \stdClass::class,
                responseClass: null,
                handlers: [],
                type: 'http_request',
                transport: null,
                produces: null,
                consumes: null,
                module: 'core',
            ),
            request: new Request(
                method: 'POST',
                uri: '/profile',
                headers: [],
                query: [],
                post: [],
                server: [],
                cookies: ['semitexa_session' => 'session-id'],
            ),
        );

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('CSRF validation failed: no auth context.');

        $listener->handle($context);
    }
}
