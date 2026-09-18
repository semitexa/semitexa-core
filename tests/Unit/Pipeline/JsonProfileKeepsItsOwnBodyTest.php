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
 * A page document is a projection OF A PAGE, and a route that renders its own
 * JSON must not be projected into one.
 *
 * MEASURED 2026-09-18 on `/playground/customers`, the reference multi-profile
 * route in this workspace, before the guard existed:
 *   Accept: application/ld+json  -> the real collection (Acme Corp, Beta LLC…)
 *   Accept: application/json     -> {"page":…,"content":{"slot":"main","data":[]}}
 * The endpoint could not answer the one Accept header its own curl examples
 * use. The projector fills `content.data` from the RENDER CONTEXT — a page
 * concept a JSON resource has no reason to fill — and the JSON rendering step
 * then replaces the body the resource had produced. Nothing was reported: the
 * response was 200, well-formed, and empty.
 *
 * The gate asked only what the CLIENT sent. What the ROUTE declared is the
 * other half of the question.
 */
final class JsonProfileKeepsItsOwnBodyTest extends TestCase
{
    /** A resource that renders itself, the way a JSON API response does. */
    private function apiResource(string $body): object
    {
        return new class($body) {
            private string $content;
            /** @var array<string, string> */
            private array $headers = [];

            public function __construct(string $content)
            {
                $this->content = $content;
            }

            // A handle is what makes a route page-projectable, and a JSON
            // resource legitimately has one: it names the resource in metadata
            // and in its IRIs. That is why the handle alone was never enough
            // to decide this.
            public function getRenderHandle(): string
            {
                return 'playground.customer.list';
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

    /** @param list<string>|null $produces */
    private function route(mixed $renderProfile, ?array $produces = ['text/html', 'application/json']): DiscoveredRoute
    {
        return new DiscoveredRoute(
            path: '/playground/customers',
            methods: ['GET'],
            name: 'customers',
            requestClass: 'Stub\\Payload',
            responseClass: 'Stub\\Response',
            handlers: [],
            type: 'http_request',
            transport: null,
            // The real shape of these routes: a page that can also answer JSON.
            // Without a produces list nothing negotiates to JSON at all and the
            // test would exercise the layout path while claiming to test this
            // one.
            produces: $produces,
            consumes: null,
            module: 'stub',
            renderProfile: $renderProfile,
        );
    }

    private function get(string $accept): Request
    {
        return new Request(
            method: 'GET',
            uri: '/playground/customers',
            headers: ['Accept' => $accept],
            query: [],
            post: [],
            server: ['request_uri' => '/playground/customers'],
            cookies: [],
        );
    }

    private function render(mixed $renderProfile, string $accept): string
    {
        $resource = $this->apiResource('{"data":[{"id":"123"}]}');
        $rendered = (new ResponseRenderer())->render($resource, null, $this->get($accept), $this->route($renderProfile));

        self::assertTrue(method_exists($rendered, 'getContent'));

        return (string) $rendered->getContent();
    }

    #[Test]
    public function a_route_declaring_the_json_profile_answers_with_its_own_body(): void
    {
        $body = $this->render(RenderProfile::Json, 'application/json');

        self::assertSame('{"data":[{"id":"123"}]}', $body);
        self::assertStringNotContainsString('"page"', $body, 'the resource was wrapped in a page document');
    }

    #[Test]
    public function a_list_of_profiles_counts_as_long_as_json_is_in_it(): void
    {
        // The real declaration on /playground/customers.
        $profiles = [RenderProfile::Json, RenderProfile::JsonLd, RenderProfile::GraphQL];

        self::assertSame('{"data":[{"id":"123"}]}', $this->render($profiles, 'application/json'));
    }

    /**
     * The guard is about the declaration, not about the Accept header, so a
     * query-string request for JSON on the same route behaves the same way.
     */
    #[Test]
    public function the_format_query_does_not_reopen_the_projection(): void
    {
        $resource = $this->apiResource('{"data":[{"id":"123"}]}');
        $request = new Request(
            method: 'GET',
            uri: '/playground/customers?_format=json',
            headers: [],
            query: ['_format' => 'json'],
            post: [],
            server: ['request_uri' => '/playground/customers?_format=json'],
            cookies: [],
        );

        $rendered = (new ResponseRenderer())->render($resource, null, $request, $this->route(RenderProfile::Json));

        self::assertSame('{"data":[{"id":"123"}]}', (string) $rendered->getContent());
    }

    /**
     * AND THE HALF THAT MUST NOT MOVE. A page route declares no render profile,
     * so every page keeps answering `Accept: application/json` with its page
     * document exactly as before — that is what the shell client, the SEO
     * tooling and `?_format=json` are built on.
     */
    #[Test]
    public function a_route_that_declares_no_profile_is_still_projected(): void
    {
        $body = $this->render(null, 'application/json');

        self::assertStringContainsString('"page"', $body, 'a page route lost its page document');
        self::assertStringContainsString('"handle":"playground.customer.list"', $body);
    }

    /**
     * A profile that is NOT Json says nothing about what `application/json`
     * should be, so it must not switch the projection off either — otherwise
     * one route declaring `html` would quietly change what every JSON client
     * of it receives.
     */
    #[Test]
    public function a_non_json_profile_does_not_disable_the_projection(): void
    {
        $body = $this->render(RenderProfile::Html, 'application/json');

        self::assertStringContainsString('"page"', $body);
    }

    #[Test]
    public function a_html_request_is_untouched_either_way(): void
    {
        // Not JSON at all: this path never reached the projector, and still
        // does not. The resource's own content is returned as it was.
        self::assertSame('{"data":[{"id":"123"}]}', $this->render(RenderProfile::Json, 'text/html'));
        self::assertSame('{"data":[{"id":"123"}]}', $this->render(null, 'text/html'));
    }

    /**
     * THE SECOND HALF OF THE SAME LOSS. Skipping the page projection is not
     * enough on its own: the JSON rendering step then encoded the RENDER
     * CONTEXT over the body the handler had produced. A handler that built the
     * collection and set its Deprecation and Sunset headers kept the headers
     * and lost the collection — a 200 that reads like a working API and
     * carries nothing.
     */
    #[Test]
    public function a_body_the_handler_produced_is_not_overwritten_by_the_context(): void
    {
        $resource = new class {
            private string $content = '{"data":[{"id":"p-1"}],"meta":{"total":1}}';
            /** @var array<string, string> */
            private array $headers = [];

            public function getRenderHandle(): string { return 'demo_feature'; }
            /** @return array<string, mixed> */
            public function getRenderContext(): array { return ['section' => 'api', 'slug' => 'sunset-version']; }
            public function getContent(): string { return $this->content; }
            public function setContent(string $content): void { $this->content = $content; }
            public function setHeader(string $name, string $value): void { $this->headers[$name] = $value; }
            /** @return array<string, string> */
            public function getHeaders(): array { return $this->headers; }
        };

        $rendered = (new ResponseRenderer())->render(
            $resource,
            null,
            $this->get('application/json'),
            $this->route([RenderProfile::Html, RenderProfile::Json]),
        );

        self::assertSame('{"data":[{"id":"p-1"}],"meta":{"total":1}}', (string) $rendered->getContent());
        self::assertStringNotContainsString('sunset-version', (string) $rendered->getContent(), 'the render context was written over the body');
    }

    /**
     * And the ordinary case is untouched: a resource that produced no body of
     * its own still has its render context encoded, which is what every plain
     * JSON resource relies on.
     */
    #[Test]
    public function a_resource_with_no_body_still_gets_its_context_encoded(): void
    {
        $resource = new class {
            private string $content = '';
            /** @var array<string, string> */
            private array $headers = [];

            public function getRenderHandle(): string { return 'feed'; }
            /** @return array<string, mixed> */
            public function getRenderContext(): array { return ['data' => [1, 2, 3]]; }
            public function getContent(): string { return $this->content; }
            public function setContent(string $content): void { $this->content = $content; }
            public function setHeader(string $name, string $value): void { $this->headers[$name] = $value; }
            /** @return array<string, string> */
            public function getHeaders(): array { return $this->headers; }
        };

        $rendered = (new ResponseRenderer())->render(
            $resource,
            null,
            $this->get('application/json'),
            $this->route(RenderProfile::Json),
        );

        self::assertSame('{"data":[1,2,3]}', (string) $rendered->getContent());
        self::assertSame('application/json', $rendered->getHeaders()['Content-Type'] ?? null);
    }

    /**
     * The JSON path labels the body `application/json`, and it does so
     * unconditionally — the first draft of the body guard also tried to keep a
     * Content-Type the resource already carried, which sounds right and is not:
     * a bare resource is BORN with `text/html`, so /platform/calendar/events
     * shipped correct JSON labelled as HTML. A default is not a declaration.
     */
    #[Test]
    public function the_json_path_always_labels_the_body_as_json(): void
    {
        $resource = new class {
            private string $content = '{"data":[]}';
            /** @var array<string, string> */
            private array $headers = ['Content-Type' => 'text/html'];

            public function getRenderHandle(): string { return 'feed'; }
            /** @return array<string, mixed> */
            public function getRenderContext(): array { return []; }
            public function getContent(): string { return $this->content; }
            public function setContent(string $content): void { $this->content = $content; }
            public function setHeader(string $name, string $value): void { $this->headers[$name] = $value; }
            /** @return array<string, string> */
            public function getHeaders(): array { return $this->headers; }
        };

        $rendered = (new ResponseRenderer())->render(
            $resource,
            null,
            $this->get('application/json'),
            $this->route(RenderProfile::Json),
        );

        self::assertSame('application/json', $rendered->getHeaders()['Content-Type'] ?? null);
        self::assertSame('{"data":[]}', (string) $rendered->getContent(), 'and the body is still the handler\'s');
    }

    /**
     * A route that renders its own JSON still has to be RENDERED as JSON, and
     * `produces` is not what says so — /playground/customers declares none.
     * Skipping the projection without this left the response on the LAYOUT
     * path: the body was the handler's, correctly, under a `text/html` label.
     * A correct body with the wrong content type is the shape a client cannot
     * recover from on its own.
     */
    #[Test]
    public function a_json_profile_route_without_a_produces_list_is_still_rendered_as_json(): void
    {
        $resource = $this->apiResource('{"data":[{"id":"123"}]}');

        $rendered = (new ResponseRenderer())->render(
            $resource,
            null,
            $this->get('application/json'),
            $this->route(RenderProfile::Json, produces: null),
        );

        self::assertSame('{"data":[{"id":"123"}]}', (string) $rendered->getContent());
        self::assertSame('application/json', $rendered->getHeaders()['Content-Type'] ?? null);
    }

    #[Test]
    public function the_declaration_check_reads_both_shapes_and_nothing_else(): void
    {
        $declares = new \ReflectionMethod(ResponseRenderer::class, 'declaresJsonProfile');
        $declares->setAccessible(true);

        self::assertTrue($declares->invoke(null, $this->route(RenderProfile::Json)));
        self::assertTrue($declares->invoke(null, $this->route([RenderProfile::JsonLd, RenderProfile::Json])));
        self::assertFalse($declares->invoke(null, $this->route(RenderProfile::JsonLd)));
        self::assertFalse($declares->invoke(null, $this->route([RenderProfile::Html])));
        self::assertFalse($declares->invoke(null, $this->route(null)));
        // A malformed declaration is not a JSON declaration. Reading a string
        // here as "json" would let a typo turn the projection off silently.
        self::assertFalse($declares->invoke(null, $this->route('json')));
        self::assertFalse($declares->invoke(null, $this->route([])));
    }
}
