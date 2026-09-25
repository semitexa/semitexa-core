<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use Semitexa\Core\Tests\Support\StaticState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Pipeline\ResponseRenderer;
use Semitexa\Core\Request;
use Semitexa\Locale\Context\LocaleContextStore;

/**
 * A redirect is still the handler's response. The headers it set on its
 * resource — Cache-Control: no-store on a login, Clear-Site-Data on a logout —
 * have to reach the client exactly as they do on any other response.
 */
final class RedirectKeepsResourceHeadersTest extends TestCase
{
    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $localeState;

    protected function setUp(): void
    {
        // Snapshot BEFORE resetting: whatever an earlier test left in the
        // store is put back in tearDown(), so this class leaves no trace.
        $this->localeState = StaticState::snapshot(LocaleContextStore::class);
        LocaleContextStore::clearFallback();
        LocaleContextStore::setUrlPrefixEnabled(false);
    }

    protected function tearDown(): void
    {
        StaticState::restore($this->localeState);
    }

    #[Test]
    public function headers_the_handler_set_survive_the_redirect(): void
    {
        $resource = (new ResourceResponse())
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('Clear-Site-Data', '"cache", "storage"')
            ->setRedirect('/login', 303);

        $response = $this->render($resource);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaders()['Location'] ?? null);
        self::assertSame('no-store', $response->getHeaders()['Cache-Control'] ?? null);
        self::assertSame('"cache", "storage"', $response->getHeaders()['Clear-Site-Data'] ?? null);
    }

    /** The validated target is the only Location, whatever case the handler used. */
    #[Test]
    public function the_validated_location_wins_over_one_the_handler_set(): void
    {
        $resource = (new ResourceResponse())
            ->setHeader('location', 'https://evil.com/')
            ->setRedirect('https://evil.com/');

        $response = $this->render($resource);

        $locations = array_filter(
            $response->getHeaders(),
            static fn (string $name): bool => strtolower($name) === 'location',
            ARRAY_FILTER_USE_KEY,
        );
        self::assertSame(['Location' => '/'], $locations);
    }

    private function render(ResourceResponse $resource): HttpResponse
    {
        $request = new Request(
            method: 'POST',
            uri: '/logout',
            headers: ['Host' => 'site.test'],
            query: [],
            post: [],
            server: ['request_uri' => '/logout'],
            cookies: [],
        );
        $route = new DiscoveredRoute(
            path: '/logout',
            methods: ['POST'],
            name: 'logout',
            requestClass: 'Stub\\Payload',
            responseClass: null,
            handlers: [],
            type: 'http_request',
            transport: null,
            produces: null,
            consumes: null,
            module: 'stub',
        );

        $response = (new ResponseRenderer())->render($resource, null, $request, $route);
        self::assertInstanceOf(HttpResponse::class, $response);

        return $response;
    }
}
