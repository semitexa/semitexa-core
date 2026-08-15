<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;

final class RequestTest extends TestCase
{
    public function testGetHostParsesHostHeader(): void
    {
        $request = new Request(
            'GET',
            '/',
            ['Host' => 'Example.COM:8443'],
            [],
            [],
            ['remote_addr' => '203.0.113.10'],
            [],
        );

        $this->assertSame('example.com', $request->getHost());
    }

    public function testGetSchemeIgnoresUntrustedForwardedProto(): void
    {
        $request = new Request(
            'GET',
            '/',
            ['X-Forwarded-Proto' => 'javascript'],
            [],
            [],
            ['REMOTE_ADDR' => '203.0.113.10', 'HTTPS' => 'on'],
            [],
        );

        $this->assertSame('https', $request->getScheme());
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
    }

    private function forwardedHttps(string $remoteAddr): Request
    {
        return new Request(
            'GET',
            '/',
            ['X-Forwarded-Proto' => 'https'],
            [],
            [],
            ['REMOTE_ADDR' => $remoteAddr],
            [],
        );
    }

    public function testBridgeProxyIsNotTrustedByDefault(): void
    {
        // The compose topology from #102: proxy is a sibling container.
        $this->assertSame('http', $this->forwardedHttps('172.18.0.3')->getScheme());
    }

    public function testTrustedProxiesCidrAdmitsTheBridgeProxy(): void
    {
        putenv('TRUSTED_PROXIES=172.18.0.0/16');

        $this->assertSame('https', $this->forwardedHttps('172.18.0.3')->getScheme());
        // Same env, an address OUTSIDE the block stays untrusted.
        $this->assertSame('http', $this->forwardedHttps('172.19.0.3')->getScheme());
    }

    public function testTrustedProxiesAcceptsBareIpAndAList(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.5, 192.0.2.0/24');

        $this->assertSame('https', $this->forwardedHttps('10.0.0.5')->getScheme());
        $this->assertSame('https', $this->forwardedHttps('192.0.2.77')->getScheme());
        $this->assertSame('http', $this->forwardedHttps('10.0.0.6')->getScheme());
    }

    public function testGarbageTrustedProxiesEntriesTrustNothing(): void
    {
        putenv('TRUSTED_PROXIES=not-an-ip, 172.18.0.0/99, /16, ');

        $this->assertSame('http', $this->forwardedHttps('172.18.0.3')->getScheme());
    }

    public function testTrustedProxiesIpv6Cidr(): void
    {
        putenv('TRUSTED_PROXIES=fd00::/8');

        $this->assertSame('https', $this->forwardedHttps('fd12:3456::1')->getScheme());
        $this->assertSame('http', $this->forwardedHttps('fe80::1')->getScheme());
    }

    public function testGetSchemeTrustsForwardedProtoForLoopbackProxy(): void
    {
        $request = new Request(
            'GET',
            '/',
            ['X-Forwarded-Proto' => 'https'],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            [],
        );

        $this->assertSame('https', $request->getScheme());
    }

    public function testGetOriginReturnsEmptyStringWithoutHost(): void
    {
        $request = new Request(
            'GET',
            '/',
            [],
            [],
            [],
            ['HTTPS' => 'on'],
            [],
        );

        $this->assertSame('', $request->getOrigin());
    }
}
