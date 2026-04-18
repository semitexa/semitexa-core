<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;

final class RequestTest extends TestCase
{
    public function testGetHostIgnoresUntrustedForwardedHost(): void
    {
        $request = new Request(
            'GET',
            '/',
            ['Host' => 'safe.example', 'X-Forwarded-Host' => 'evil.example'],
            [],
            [],
            ['remote_addr' => '203.0.113.10'],
            [],
        );

        self::assertSame('safe.example', $request->getHost());
    }

    public function testGetSchemeIgnoresUntrustedForwardedProto(): void
    {
        $request = new Request(
            'GET',
            '/',
            ['X-Forwarded-Proto' => 'javascript'],
            [],
            [],
            ['remote_addr' => '203.0.113.10', 'https' => 'on'],
            [],
        );

        self::assertSame('https', $request->getScheme());
    }
}
