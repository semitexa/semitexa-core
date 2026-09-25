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
    public function the_served_path_is_the_one_the_client_asked_for(): void
    {
        // What the ROUTER matched and what the VISITOR is looking at are two
        // different questions once a prefix has been stripped, and anything
        // that reports an address — the shell envelope the client pushState's
        // — has to answer the second one.
        $rebased = $this->get('/ka/gallery?sort=price_asc')->withPath('/gallery');

        self::assertSame('/gallery', $rebased->getPath(), 'routing still works on the stripped path');
        self::assertSame('/ka/gallery', $rebased->getServedPath());
    }

    #[Test]
    public function an_untouched_request_serves_the_path_it_routes(): void
    {
        self::assertSame('/gallery', $this->get('/gallery')->getServedPath());
    }

    #[Test]
    public function a_request_with_no_server_entry_falls_back_to_its_own_path(): void
    {
        // A hand-built request in a test, a replayed one from the Observatory:
        // nothing rebased it, so the two answers are the same by construction.
        $request = new Request(
            method: 'GET',
            uri: '/gallery',
            headers: [],
            query: [],
            post: [],
            server: [],
            cookies: [],
        );

        self::assertSame('/gallery', $request->getServedPath());
    }

    #[Test]
    public function a_request_target_is_read_as_a_path_not_as_a_url(): void
    {
        // parse_url() takes a leading `//` for an authority and gives up on a
        // colon it reads as a port: `//evil.com/admin` came out as `/admin`,
        // and `/events/10:00` as `false`, which routed to `/`.
        $cases = [
            '//evil.com/admin' => '//evil.com/admin',
            '///admin/users' => '///admin/users',
            '/events/10:00' => '/events/10:00',
            '/x:1/y' => '/x:1/y',
            '/foo:99999?a=1' => '/foo:99999',
            '/gallery#top' => '/gallery',
        ];

        foreach ($cases as $uri => $expected) {
            $request = $this->get($uri);
            self::assertSame($expected, $request->getPath(), $uri);
            self::assertSame($expected, $request->getServedPath(), $uri);
        }
    }

    #[Test]
    public function an_absolute_form_target_still_yields_its_path(): void
    {
        self::assertSame('/x', $this->get('http://example.com/x?a=1')->getPath());
    }

    #[Test]
    public function an_empty_path_before_the_query_is_the_root(): void
    {
        self::assertSame('/', $this->get('?a=1')->getPath());
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
