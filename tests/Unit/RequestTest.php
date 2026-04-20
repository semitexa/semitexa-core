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
