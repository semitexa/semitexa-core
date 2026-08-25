<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Request;

/**
 * A path rewritten ahead of the handler — a locale prefix being stripped — has
 * to reach the request itself, because path parameters are hydrated by matching
 * the route pattern against the request's own path.
 */
final class RequestWithPathTest extends TestCase
{
    #[Test]
    public function rebasing_keeps_the_query_string(): void
    {
        $rebased = $this->get('/uk/listing/abc?sort=price_asc&page=2')->withPath('/listing/abc');

        self::assertSame('/listing/abc', $rebased->getPath());
        self::assertSame('sort=price_asc&page=2', $rebased->getQueryString());
    }

    #[Test]
    public function rebasing_onto_the_same_path_returns_the_same_instance(): void
    {
        $request = $this->get('/listing/abc');

        self::assertSame($request, $request->withPath('/listing/abc'));
    }

    #[Test]
    public function the_original_uri_survives_on_server_so_the_locale_is_still_knowable(): void
    {
        $rebased = $this->get('/uk/listing/abc')->withPath('/listing/abc');

        self::assertSame('/uk/listing/abc', $rebased->server['request_uri']);
    }

    #[Test]
    public function a_prefixed_path_hydrates_no_route_parameter(): void
    {
        // The defect this exists to prevent: the pattern never carries /uk, so
        // matching it against the raw path yields nothing and `id` stays null.
        $hydrated = PayloadHydrator::hydrate($this->listingPayload(), $this->get('/uk/listing/abc'));

        self::assertNull($hydrated->id);
    }

    #[Test]
    public function the_rebased_request_hydrates_the_route_parameter(): void
    {
        $request = $this->get('/uk/listing/abc')->withPath('/listing/abc');

        $hydrated = PayloadHydrator::hydrate($this->listingPayload(), $request);

        self::assertSame('abc', $hydrated->id);
    }

    private function listingPayload(): object
    {
        return new #[\Semitexa\Core\Attribute\AsPublicPayload(path: '/listing/{id}')] class {
            public ?string $id = null;

            public function setId(string $value): void
            {
                $this->id = $value;
            }
        };
    }

    private function get(string $uri): Request
    {
        return new Request(
            method: 'GET',
            uri: $uri,
            headers: [],
            query: [],
            post: [],
            server: ['request_uri' => $uri],
            cookies: [],
        );
    }
}
