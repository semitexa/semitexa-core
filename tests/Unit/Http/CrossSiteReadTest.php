<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\CrossSiteRead;
use Semitexa\Core\Request;

/**
 * The one rule the developer surfaces (Observatory, UI Workbench) use to
 * refuse a page on another site that could read their responses.
 */
final class CrossSiteReadTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: array<string, string>, 2: bool, 3?: array<string, string>}> */
    public static function requests(): iterable
    {
        yield 'no browser headers (curl, a test)' => ['GET', [], false];
        yield 'same-origin fetch' => ['GET', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors'], false];
        yield 'typed into the address bar' => ['GET', ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'], false];
        yield 'a link followed from another site' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'], false];
        yield 'a form POSTed from another site is not a navigation exemption' => ['POST', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'], true];
        yield 'fetch from another site' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'cors'], true];
        yield 'fetch from a sibling subdomain' => ['GET', ['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Mode' => 'cors'], true];
        yield 'iframe from another site' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe'], true];
        yield 'own origin without Sec-Fetch' => ['GET', ['Origin' => 'http://127.0.0.1:9507', 'Host' => '127.0.0.1:9507'], false];
        yield 'foreign origin without Sec-Fetch' => ['GET', ['Origin' => 'https://evil.example', 'Host' => '127.0.0.1:9507'], true];
        yield 'opaque origin' => ['GET', ['Origin' => 'null', 'Host' => '127.0.0.1:9507'], true];
        yield 'origin but no host to compare with' => ['GET', ['Origin' => 'http://127.0.0.1:9507'], true];
        // An origin is scheme, host and port (tk-cross-site-read-origin-port-and-scheme, review semitexa-dev#307).
        yield 'same host on another port, the Host naming none' => ['GET', ['Origin' => 'http://localhost:3000', 'Host' => 'localhost'], true];
        yield 'same host on another port' => ['GET', ['Origin' => 'http://127.0.0.1:3000', 'Host' => '127.0.0.1:9507'], true];
        yield 'same host on another scheme' => ['GET', ['Origin' => 'https://example.test', 'Host' => 'example.test'], true];
        yield 'an https request from the http origin of its host' => ['GET', ['Origin' => 'http://example.test', 'Host' => 'example.test'], true, ['https' => 'on']];
        yield 'own https origin' => ['GET', ['Origin' => 'https://example.test', 'Host' => 'example.test'], false, ['https' => 'on']];
        yield 'own origin with its default port written out' => ['GET', ['Origin' => 'http://example.test:80', 'Host' => 'example.test'], false];
        yield 'own origin, the Host naming the default port' => ['GET', ['Origin' => 'http://example.test', 'Host' => 'example.test:80'], false];
        yield 'own origin in another case' => ['GET', ['Origin' => 'http://Example.TEST', 'Host' => 'example.test'], false];
        yield 'own origin, the Host in another case' => ['GET', ['Origin' => 'http://example.test', 'Host' => 'Example.TEST'], false];
        yield 'own origin, its scheme in capitals' => ['GET', ['Origin' => 'HTTP://example.test', 'Host' => 'example.test'], false];
        yield 'own https origin with its default port written out' => ['GET', ['Origin' => 'https://example.test:443', 'Host' => 'example.test'], false, ['https' => 'on']];
        yield 'https from a trusted proxy (loopback)' => ['GET', ['Origin' => 'https://app.example', 'Host' => 'app.example', 'X-Forwarded-Proto' => 'https'], false, ['remote_addr' => '127.0.0.1']];
        yield 'https from a proxy that is not trusted reads as http' => ['GET', ['Origin' => 'https://app.example', 'Host' => 'app.example', 'X-Forwarded-Proto' => 'https'], true, ['remote_addr' => '172.18.0.5']];
        yield 'IPv6, another port' => ['GET', ['Origin' => 'http://[::1]:3000', 'Host' => '[::1]'], true];
        yield 'IPv6, own origin' => ['GET', ['Origin' => 'http://[::1]:9507', 'Host' => '[::1]:9507'], false];
        yield 'a scheme other than http or https' => ['GET', ['Origin' => 'file://localhost', 'Host' => 'localhost'], true];
        yield 'a Host with a user part' => ['GET', ['Origin' => 'http://evil.example', 'Host' => 'localhost@evil.example'], true];
        yield 'a Host with a path' => ['GET', ['Origin' => 'http://localhost:9507', 'Host' => 'localhost:9507/'], true];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    #[Test]
    #[DataProvider('requests')]
    public function decides_whether_another_site_could_read_the_response(string $method, array $headers, bool $crossSite, array $server = []): void
    {
        $request = new Request($method, '/__ui/workbench', $headers, [], [], $server, []);

        self::assertSame($crossSite, CrossSiteRead::isCrossSiteRead($request));
    }

    #[Test]
    public function no_request_is_not_a_cross_site_read(): void
    {
        self::assertFalse(CrossSiteRead::isCrossSiteRead(null));
    }
}
