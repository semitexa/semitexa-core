<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Pipeline\ResponseRenderer;
use Semitexa\Core\Request;
use Semitexa\Core\Resource\RenderProfile;

/**
 * A body a resource produced under a data profile goes out under that
 * profile's type, not as a page.
 *
 * MEASURED 2026-09-22 across every parameterless GET route in the workspace x
 * four Accept headers: 21 JSON bodies on 7 routes were labelled text/html.
 * Two shapes, one cause — the type was taken from the path through the
 * renderer rather than from what the resource is:
 *
 *   /platform/calendar/events     no render handle, so render() returned the
 *                                 resource untouched and Swoole's default
 *                                 text/html went out
 *   /playground/customers, feeds  a handle and no negotiated format, so the
 *                                 layout path saw a body and stamped
 *                                 text/html over the application/json or
 *                                 application/ld+json the class had set
 */
final class DataProfileBodyIsNotLabelledAsPageTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function resource(?string $handle, string $body, array $headers = []): object
    {
        return new class($handle, $body, $headers) {
            /** @param array<string, string> $headers */
            public function __construct(
                private ?string $handle,
                private string $content,
                private array $headers,
            ) {
            }

            public function getRenderHandle(): ?string
            {
                return $this->handle;
            }

            /** @return array<string, mixed> */
            public function getRenderContext(): array
            {
                return [];
            }

            public function getContent(): string
            {
                return $this->content;
            }

            public function setContent(string $content): void
            {
                $this->content = $content;
            }

            public function setHeader(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }

            /** @return array<string, string> */
            public function getHeaders(): array
            {
                return $this->headers;
            }
        };
    }

    /**
     * @param array<string, class-string>|null $responsesByProfile
     * @param list<string>|null $produces
     */
    private function route(mixed $renderProfile, ?array $responsesByProfile = null, ?array $produces = null): DiscoveredRoute
    {
        return new DiscoveredRoute(
            path: '/feed',
            methods: ['GET'],
            name: 'feed',
            requestClass: 'Stub\\Payload',
            responseClass: 'Stub\\Response',
            handlers: [],
            type: 'http_request',
            transport: null,
            produces: $produces,
            consumes: null,
            module: 'stub',
            renderProfile: $renderProfile,
            responsesByProfile: $responsesByProfile,
        );
    }

    private function get(string $accept): Request
    {
        return new Request(
            method: 'GET',
            uri: '/feed',
            headers: ['Accept' => $accept],
            query: [],
            post: [],
            server: ['request_uri' => '/feed'],
            cookies: [],
        );
    }

    /** @return array<string, string> */
    private function headersOf(object $rendered): array
    {
        self::assertTrue(method_exists($rendered, 'getHeaders'));
        $headers = $rendered->getHeaders();
        self::assertIsArray($headers);

        return $headers;
    }

    /** The calendar feed: no handle, no type of its own. */
    #[Test]
    public function an_unlabelled_body_without_a_handle_takes_its_profile_type(): void
    {
        $resource = $this->resource(null, '{"data":[]}');

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('application/json'), $this->route(RenderProfile::Json));

        self::assertSame('application/json', $this->headersOf($rendered)['Content-Type'] ?? null);
        self::assertSame('{"data":[]}', $rendered->getContent());
    }

    /**
     * The grid feeds: a handle, a type the class set itself, and a browser's
     * Accept that negotiates no format — the case the layout path relabelled.
     */
    #[Test]
    public function a_declared_type_survives_an_accept_that_negotiates_nothing(): void
    {
        $resource = $this->resource('playground.article.list', '{"data":[]}', ['Content-Type' => 'application/json']);

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/html'), $this->route(RenderProfile::Json));

        self::assertSame('application/json', $this->headersOf($rendered)['Content-Type'] ?? null);
        self::assertSame('{"data":[]}', $rendered->getContent());
    }

    /**
     * /playground/customers under Accept: application/ld+json. The class says
     * ld+json, and that is kept — not flattened to the Json profile's type.
     */
    #[Test]
    public function the_profile_served_is_the_one_the_response_class_is_mapped_to(): void
    {
        $resource = $this->resource('playground.customer.list', '{"@graph":[]}', ['Content-Type' => 'application/ld+json']);
        $route = $this->route(
            [RenderProfile::Json, RenderProfile::JsonLd],
            ['json' => 'Stub\\JsonResponse', 'json-ld' => $resource::class],
        );

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('application/ld+json'), $route);

        self::assertSame('application/ld+json', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /** With no type of its own, the mapped profile supplies it. */
    #[Test]
    public function an_unlabelled_body_takes_the_type_of_the_profile_it_was_served_for(): void
    {
        $resource = $this->resource('playground.customer.list', '{"@graph":[]}');
        $route = $this->route(
            [RenderProfile::Json, RenderProfile::JsonLd],
            ['json' => 'Stub\\JsonResponse', 'json-ld' => $resource::class],
        );

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('application/ld+json'), $route);

        self::assertSame('application/ld+json', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /**
     * A type the handler chose is a decision, not a default, and the profile
     * does not overrule it: a JSON route may still hand out an export.
     */
    #[Test]
    public function a_type_the_handler_chose_is_not_overruled_by_the_profile(): void
    {
        $resource = $this->resource(null, "id,name\n1,Acme\n", ['Content-Type' => 'text/csv; charset=utf-8']);

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('*/*'), $this->route(RenderProfile::Json));

        self::assertSame('text/csv; charset=utf-8', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /**
     * A route may declare the Json profile and still produce text/html. A
     * browser's Accept then NEGOTIATES Layout — a choice, not an absence — and
     * the body the class already labelled as JSON must keep that label.
     */
    #[Test]
    public function a_declared_type_survives_a_negotiated_layout(): void
    {
        $resource = $this->resource('playground.article.list', '{"data":[]}', ['Content-Type' => 'application/json']);
        $route = $this->route(RenderProfile::Json, null, ['text/html', 'application/json']);

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/html'), $route);

        self::assertSame('application/json', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /**
     * Under that same explicit HTML choice an UNLABELLED body may well be the
     * HTML the handler produced for it, so it is left to the layout path.
     */
    #[Test]
    public function an_unlabelled_body_under_a_negotiated_layout_stays_html(): void
    {
        $resource = $this->resource('playground.article.list', '<main>list</main>');
        $route = $this->route(RenderProfile::Json, null, ['text/html', 'application/json']);

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/html'), $route);

        self::assertSame('text/html; charset=utf-8', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /**
     * A route may map a parent class and its child to different profiles, and
     * the instance may be a generated subclass of the child. The child's
     * profile wins, even with the parent declared first.
     */
    #[Test]
    public function the_most_specific_mapped_class_decides_the_profile(): void
    {
        $resource = new class ('playground.customer.list', '{"@graph":[]}', []) extends DataProfileChildResponseStub {};
        $route = $this->route(
            [RenderProfile::Json, RenderProfile::JsonLd],
            ['json' => DataProfileParentResponseStub::class, 'json-ld' => DataProfileChildResponseStub::class],
        );

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('application/ld+json'), $route);

        self::assertSame('application/ld+json', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /** A page is still a page: no profile, a rendered body, text/html. */
    #[Test]
    public function a_page_with_a_body_is_still_labelled_as_html(): void
    {
        $resource = $this->resource('demo.page', '<main>hello</main>');

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/html'), $this->route(null));

        self::assertSame('text/html; charset=utf-8', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /** Html is a profile too, and it is a page one: it stays on the layout path. */
    #[Test]
    public function a_route_declaring_the_html_profile_is_still_a_page(): void
    {
        $resource = $this->resource('demo.page', '<main>hello</main>');

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/html'), $this->route(RenderProfile::Html));

        self::assertSame('text/html; charset=utf-8', $this->headersOf($rendered)['Content-Type'] ?? null);
    }

    /** And a route that states no profile and has no handle is left exactly as it was. */
    #[Test]
    public function a_bare_resource_without_a_profile_is_not_labelled(): void
    {
        $resource = $this->resource(null, 'plain');

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('*/*'), $this->route(null));

        self::assertArrayNotHasKey('Content-Type', $this->headersOf($rendered));
    }
}

/** A response class a route can map to a profile, the way a JSON resource is shaped. */
class DataProfileParentResponseStub
{
    /** @param array<string, string> $headers */
    public function __construct(
        private ?string $handle,
        private string $content,
        private array $headers,
    ) {
    }

    public function getRenderHandle(): ?string
    {
        return $this->handle;
    }

    /** @return array<string, mixed> */
    public function getRenderContext(): array
    {
        return [];
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function setHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}

class DataProfileChildResponseStub extends DataProfileParentResponseStub
{
}
