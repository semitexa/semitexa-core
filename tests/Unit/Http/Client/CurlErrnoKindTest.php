<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\CurlHttpClient;
use Semitexa\Core\Http\Client\HttpTransportKind;

/**
 * Under the Swoole curl hook a timeout carries the system's errno (110,
 * ETIMEDOUT), not curl's 28: it was named a connection failure, and every
 * log and failover label said "network" for a model that was only slow.
 */
final class CurlErrnoKindTest extends TestCase
{
    #[Test]
    public function a_timeout_is_a_timeout_whichever_errno_names_it(): void
    {
        self::assertSame(HttpTransportKind::Timeout, self::kindOf(28), 'curl');
        self::assertSame(HttpTransportKind::Timeout, self::kindOf(110), 'the Swoole hook');
        self::assertSame(HttpTransportKind::Connect, self::kindOf(111), 'ECONNREFUSED under the hook');
        self::assertSame(HttpTransportKind::Connect, self::kindOf(7), 'refused, plain curl');
        self::assertSame(HttpTransportKind::Dns, self::kindOf(6));
        self::assertSame(HttpTransportKind::Tls, self::kindOf(60));
    }

    private static function kindOf(int $errno): HttpTransportKind
    {
        return (new \ReflectionMethod(CurlHttpClient::class, 'kindOf'))->invoke(null, $errno);
    }
}
