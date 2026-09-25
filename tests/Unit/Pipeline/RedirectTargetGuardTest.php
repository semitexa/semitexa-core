<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\DiscoveredRoute;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\HttpResponse;
use Semitexa\Core\Pipeline\ResponseRenderer;
use Semitexa\Core\Request;
use Semitexa\Locale\Context\LocaleContextStore;

/**
 * The open-redirect guard (VULN-006) decides on parse_url()'s reading of the
 * target, but the browser decides where it goes — and a browser reads `\` as `/`
 * and drops TAB/CR/LF, so a target parse_url() sees as a local path can still
 * name another host.
 */
final class RedirectTargetGuardTest extends TestCase
{
    protected function setUp(): void
    {
        LocaleContextStore::clearFallback();
        LocaleContextStore::setUrlPrefixEnabled(false);
    }

    protected function tearDown(): void
    {
        LocaleContextStore::clearFallback();
    }

    /** @return iterable<string, array{string}> */
    public static function offSiteTargets(): iterable
    {
        yield 'slash backslash' => ['/\\evil.com/'];
        yield 'double backslash' => ['\\\\evil.com/'];
        yield 'backslash slash' => ['\\/evil.com/'];
        yield 'slash tab slash' => ["/\t/evil.com/"];
        yield 'slash newline slash' => ["/\n/evil.com/"];
        yield 'slash carriage return slash' => ["/\r/evil.com/"];
        yield 'leading space scheme relative' => [' //evil.com/'];
        yield 'scheme without authority slashes' => ['https:/evil.com/'];
        yield 'scheme with no slashes' => ['http:evil.com'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
    }

    #[Test]
    #[DataProvider('offSiteTargets')]
    public function a_target_a_browser_reads_as_another_host_falls_back_to_root(string $target): void
    {
        self::assertSame('/', $this->location($target));
    }

    /** @return iterable<string, array{string, string}> */
    public static function acceptedTargets(): iterable
    {
        yield 'root relative path' => ['/app/properties', '/app/properties'];
        yield 'root relative with query' => ['/app?err=input', '/app?err=input'];
        yield 'root' => ['/', '/'];
        yield 'query only' => ['?q=1', '?q=1'];
        yield 'fragment only' => ['#top', '#top'];
        yield 'path relative' => ['page', 'page'];
        yield 'same host absolute' => ['https://site.test/app', 'https://site.test/app'];
        yield 'allowed oauth provider' => ['https://accounts.google.com/o/oauth2/auth', 'https://accounts.google.com/o/oauth2/auth'];
        yield 'foreign absolute' => ['https://evil.com/', '/'];
    }

    #[Test]
    #[DataProvider('acceptedTargets')]
    public function legitimate_targets_keep_their_existing_treatment(string $target, string $expected): void
    {
        self::assertSame($expected, $this->location($target));
    }

    private function location(string $target): string
    {
        $resource = (new ResourceResponse())->setRedirect($target);
        $request = new Request(
            method: 'POST',
            uri: '/login',
            headers: ['Host' => 'site.test'],
            query: [],
            post: [],
            server: ['request_uri' => '/login'],
            cookies: [],
        );
        $route = new DiscoveredRoute(
            path: '/login',
            methods: ['POST'],
            name: 'login',
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
        $location = $response->getHeaders()['Location'] ?? null;
        self::assertIsString($location);

        return $location;
    }
}
