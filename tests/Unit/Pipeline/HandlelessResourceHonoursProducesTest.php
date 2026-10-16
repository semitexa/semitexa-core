<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\ServedRegardlessOfAccept;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Pipeline\ResponseRenderer;
use Semitexa\Core\Request;

/**
 * A route's `produces` is a promise whatever renders the body.
 *
 * MEASURED 2026-10-16 on the demo API (10 Accept headers x 6 endpoints):
 * /demo/api/v1/products/_schema declares produces [application/json,
 * application/schema+json] and answered `Accept: application/xml` and
 * `Accept: application/json;q=0` with 200 application/json, while the
 * neighbouring endpoints, which render through a template handle, answered
 * 406. The negotiation ran only when the resource had a render handle, so on
 * a handle-less resource the declaration was inert.
 *
 * Only the refusal is the renderer's business here: a handle-less body is the
 * resource's own, so an acceptable request gets it untouched.
 */
final class HandlelessResourceHonoursProducesTest extends TestCase
{
    private const PRODUCES = ['application/json', 'application/ld+json'];

    private function resource(int $status = 200, bool $alreadySent = false): object
    {
        return new class($status, $alreadySent) {
            /** @var array<string, string> */
            private array $headers = ['Content-Type' => 'application/json'];
            private string $content = '{"data":[]}';

            public function __construct(private int $status, private bool $alreadySent)
            {
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function isAlreadySent(): bool
            {
                return $this->alreadySent;
            }

            public function getRenderHandle(): ?string
            {
                return null;
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
    private function route(?array $produces, string $requestClass = 'Stub\\Payload'): DiscoveredRoute
    {
        return new DiscoveredRoute(
            path: '/api/products',
            methods: ['GET'],
            name: 'products',
            requestClass: $requestClass,
            responseClass: 'Stub\\Response',
            handlers: [],
            type: 'http_request',
            transport: null,
            produces: $produces,
            consumes: null,
            module: 'stub',
        );
    }

    /** @param array<string, string> $query */
    private function get(?string $accept, string $method = 'GET', array $query = []): Request
    {
        return new Request(
            method: $method,
            uri: '/api/products',
            headers: $accept === null ? [] : ['Accept' => $accept],
            query: $query,
            post: [],
            server: ['request_uri' => '/api/products'],
            cookies: [],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function refusals(): iterable
    {
        yield 'a type the route does not produce' => ['application/xml'];
        yield 'the only produced type refused by name' => ['application/json;q=0, application/ld+json;q=0'];
        yield 'everything refused' => ['*/*;q=0'];
        yield 'a page only' => ['text/html'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_client_that_refuses_every_produced_type_gets_406(string $accept): void
    {
        $rendered = (new ResponseRenderer())->render($this->resource(), null, $this->get($accept), $this->route(self::PRODUCES));

        self::assertInstanceOf(HttpResponse::class, $rendered);
        self::assertSame(406, $rendered->getStatusCode());
        $body = json_decode($rendered->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(self::PRODUCES, $body['available'] ?? null);
    }

    /** @return iterable<string, array{string|null}> */
    public static function acceptable(): iterable
    {
        yield 'no Accept' => [null];
        yield 'anything' => ['*/*'];
        yield 'a browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
        yield 'an HTTP client' => ['application/json, text/plain, */*'];
        yield 'the second produced type' => ['application/ld+json'];
        // The case a full format negotiation gets wrong: JSON is refused, the
        // other produced type is wanted, and that type has no format key.
        yield 'one produced type wanted, the other refused' => ['application/ld+json, application/json;q=0'];
        yield 'a type wildcard' => ['application/*'];
    }

    #[Test]
    #[DataProvider('acceptable')]
    public function an_acceptable_request_gets_the_body_untouched(?string $accept): void
    {
        $resource = $this->resource();

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get($accept), $this->route(self::PRODUCES));

        self::assertNotInstanceOf(HttpResponse::class, $rendered);
        self::assertSame('{"data":[]}', $rendered->getContent());
    }

    /** A route that declares nothing keeps answering everyone, as before. */
    #[Test]
    public function without_produces_nothing_is_refused(): void
    {
        $rendered = (new ResponseRenderer())->render($this->resource(), null, $this->get('application/xml'), $this->route(null));

        self::assertNotInstanceOf(HttpResponse::class, $rendered);
        self::assertSame('{"data":[]}', $rendered->getContent());
    }

    /**
     * Where the refusal does not belong. Found in review of the first cut of
     * this change, each with the route that would have broken:
     *
     *   OPTIONS            the metadata probe answers JSON for a text/html route
     *   POST               /graphql answers a subscription with an SSE stream it
     *                      already wrote; a 406 emitted over it races the freed
     *                      connection
     *   already sent       same stream, flag carried by the resource
     *   401 / 404          a handler's own error is the answer the client needs
     */
    #[Test]
    #[DataProvider('notRefused')]
    public function some_answers_are_never_replaced_by_406(string $method, int $status, bool $alreadySent): void
    {
        $resource = $this->resource($status, $alreadySent);

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/event-stream', $method), $this->route(self::PRODUCES));

        self::assertSame($resource, $rendered);
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function notRefused(): iterable
    {
        yield 'an OPTIONS probe' => ['OPTIONS', 200, false];
        yield 'a POST' => ['POST', 200, false];
        yield 'a body already sent' => ['GET', 200, true];
        yield 'the handler\'s 401' => ['GET', 401, false];
        yield 'the handler\'s 404' => ['GET', 404, false];
    }

    /** A live re-run renders a frame for an open stream: nothing to negotiate. */
    #[Test]
    public function a_live_rerun_is_not_refused(): void
    {
        $resource = $this->resource();

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('text/event-stream'), $this->route(self::PRODUCES), isReRun: true);

        self::assertSame($resource, $rendered);
    }

    /** `?_format=` wins over Accept here as it does on the handle path. */
    #[Test]
    public function the_format_override_decides_before_accept(): void
    {
        $renderer = new ResponseRenderer();

        $json = $renderer->render($this->resource(), null, $this->get('application/xml', 'GET', ['_format' => 'json']), $this->route(self::PRODUCES));
        self::assertNotInstanceOf(HttpResponse::class, $json);

        $xml = $renderer->render($this->resource(), null, $this->get('application/json', 'GET', ['_format' => 'xml']), $this->route(self::PRODUCES));
        self::assertInstanceOf(HttpResponse::class, $xml);
        self::assertSame(406, $xml->getStatusCode());
    }

    /**
     * robots.txt, llms.txt and the sitemaps declare their type for the AI
     * sitemap and the route contract, and must still be served to a client
     * with an odd Accept: Google reads a 4xx robots.txt as "no rules", and
     * llms.txt is fetched by agents whose Accept nobody controls.
     */
    #[Test]
    public function a_payload_served_regardless_of_accept_is_not_refused(): void
    {
        $resource = $this->resource();

        $rendered = (new ResponseRenderer())->render($resource, null, $this->get('application/json'), $this->route(['text/plain'], ServedToAnyAcceptPayload::class));

        self::assertSame($resource, $rendered);
    }
}

#[ServedRegardlessOfAccept]
final class ServedToAnyAcceptPayload
{
}
