<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;

/**
 * Under Swoole the uri carries the path alone — parameters arrive separately —
 * so anything that rebuilt a URL from getQueryString() silently rebuilt it
 * without the query. Both locale redirects did exactly that.
 */
final class RequestQueryStringTest extends TestCase
{
    #[Test]
    public function a_query_in_the_uri_is_returned_as_written(): void
    {
        self::assertSame('sort=price_asc', $this->request('/gallery?sort=price_asc')->getQueryString());
    }

    /** The Swoole shape: path-only uri, parameters in their own array. */
    #[Test]
    public function parameters_that_never_reached_the_uri_are_still_the_query(): void
    {
        $request = $this->request('/gallery', ['sort' => 'price_asc', 'city' => 'Kyiv']);

        self::assertSame('sort=price_asc&city=Kyiv', urldecode($request->getQueryString()));
    }

    #[Test]
    public function a_request_with_no_parameters_has_no_query(): void
    {
        self::assertSame('', $this->request('/gallery')->getQueryString());
    }

    /** The path must not pick up the query that the uri never carried. */
    #[Test]
    public function the_path_is_unaffected(): void
    {
        self::assertSame('/gallery', $this->request('/gallery', ['sort' => 'price_asc'])->getPath());
    }

    /** @param array<string,mixed> $query */
    private function request(string $uri, array $query = []): Request
    {
        return new Request(
            method: 'GET',
            uri: $uri,
            headers: [],
            query: $query,
            post: [],
            server: [],
            cookies: [],
        );
    }
}
