<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\ClientIp;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Request;

/**
 * A proxy on a private address that TRUSTED_PROXIES does not list: every
 * client counts as the proxy, so one visitor's failed sign-ins lock out all
 * of them. doctor passes that deployment when SESSION_COOKIE_SECURE=always.
 */
final class ClientIpUntrustedProxyTest extends TestCase
{
    private string|false $trusted = false;

    protected function setUp(): void
    {
        $this->trusted = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES');
        (new \ReflectionProperty(ClientIp::class, 'warnedUntrustedProxy'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        putenv($this->trusted === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES=' . $this->trusted);
        (new \ReflectionProperty(ClientIp::class, 'warnedUntrustedProxy'))->setValue(null, false);
        StaticLoggerBridge::reset();
    }

    #[Test]
    public function a_forwarded_request_from_an_untrusted_private_peer_is_said_once(): void
    {
        $logger = self::logger();
        StaticLoggerBridge::set($logger);

        self::assertSame('172.18.0.5', ClientIp::of(self::request('172.18.0.5', '198.51.100.4')));
        self::assertSame('172.18.0.5', ClientIp::of(self::request('172.18.0.5', '198.51.100.9')));

        self::assertCount(1, $logger->lines, 'once per worker');
        self::assertStringContainsString('TRUSTED_PROXIES', $logger->lines[0]);
        self::assertStringContainsString('172.18.0.5', $logger->lines[0]);
    }

    #[Test]
    public function a_public_client_or_a_request_without_the_header_says_nothing(): void
    {
        $logger = self::logger();
        StaticLoggerBridge::set($logger);

        ClientIp::of(self::request('198.51.100.7', '6.6.6.6'));
        ClientIp::of(self::request('::ffff:198.51.100.7', '6.6.6.6'));
        ClientIp::of(self::request('2001:4860::8888', '6.6.6.6'));
        ClientIp::of(self::request('172.18.0.5', null));

        self::assertSame([], $logger->lines, 'a dual-stack listener names an outside IPv4 client ::ffff:a.b.c.d');
    }

    #[Test]
    public function a_private_ipv4_proxy_on_a_dual_stack_listener_is_said(): void
    {
        $logger = self::logger();
        StaticLoggerBridge::set($logger);

        ClientIp::of(self::request('::ffff:172.18.0.5', '198.51.100.4'));

        self::assertCount(1, $logger->lines);
    }

    private static function request(string $peer, ?string $forwardedFor): Request
    {
        return new Request('GET', '/', $forwardedFor === null ? [] : ['X-Forwarded-For' => $forwardedFor], [], [], ['remote_addr' => $peer], []);
    }

    private static function logger(): LoggerInterface
    {
        return new class implements LoggerInterface {
            /** @var list<string> */
            public array $lines = [];

            public function error(string $message, array $context = []): void { $this->lines[] = $message . ' ' . json_encode($context); }
            public function critical(string $message, array $context = []): void { $this->lines[] = $message . ' ' . json_encode($context); }
            public function warning(string $message, array $context = []): void { $this->lines[] = $message . ' ' . json_encode($context); }
            public function info(string $message, array $context = []): void {}
            public function notice(string $message, array $context = []): void {}
            public function debug(string $message, array $context = []): void {}
        };
    }
}
