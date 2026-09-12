<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\SwooleCompressionDoctorCheck;
use Semitexa\Core\Support\DoctorStatus;

/**
 * The branch that matters here is the one this machine cannot reach.
 *
 * The check exists for somebody else's image — one built without `zlib-dev`,
 * where Swoole carries no compression code and `http_compression` is accepted
 * and inert. On a healthy build that branch never runs, so testing only what
 * this host reports would leave the entire point of the check unexercised.
 *
 * The premise was verified against a real build before these were written: an
 * image built exactly like the scaffold's but without `zlib-dev` defines
 * neither SWOOLE_HAVE_COMPRESSION nor SWOOLE_HAVE_ZLIB. Their absence is the
 * whole diagnosis; there is no runtime setting that reveals it.
 */
final class SwooleCompressionDoctorCheckTest extends TestCase
{
    /** The case the check was written for: nothing compiled in, nothing reported by anything else. */
    #[Test]
    public function a_build_without_compression_warns_and_says_a_rebuild_is_the_fix(): void
    {
        $result = SwooleCompressionDoctorCheck::verdict(false, []);

        self::assertSame(DoctorStatus::Warn, $result->status);
        self::assertStringContainsString('accepted and inert', $result->message);
        self::assertStringContainsString('zlib-dev', (string) $result->hint);
        self::assertStringContainsString(
            'Upgrading Semitexa packages alone will not fix this',
            (string) $result->hint,
            'the fix is a rebuild, and a reader who upgrades instead will see no change',
        );
    }

    /**
     * Compression compiled in is not the same as gzip compiled in. Brotli or
     * zstd alone satisfies the macro while leaving most real clients
     * uncompressed, so the macro is not enough to report health.
     */
    #[Test]
    public function compression_without_gzip_still_warns(): void
    {
        $result = SwooleCompressionDoctorCheck::verdict(true, ['brotli', 'zstd']);

        self::assertSame(DoctorStatus::Warn, $result->status);
        self::assertStringContainsString('brotli, zstd', $result->message);
    }

    #[Test]
    public function gzip_present_is_reported_as_healthy_and_names_what_is_there(): void
    {
        $result = SwooleCompressionDoctorCheck::verdict(true, ['gzip']);

        self::assertSame(DoctorStatus::Pass, $result->status);
        self::assertStringContainsString('gzip', $result->message);

        $all = SwooleCompressionDoctorCheck::verdict(true, ['gzip', 'brotli', 'zstd']);
        self::assertSame(DoctorStatus::Pass, $all->status);
        self::assertStringContainsString('gzip, brotli, zstd', $all->message);
    }

    /** This host is healthy, and the live check agrees — the other half of the pair. */
    #[Test]
    public function the_live_check_passes_on_this_build(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole is not loaded here');
        }

        self::assertSame(DoctorStatus::Pass, (new SwooleCompressionDoctorCheck())->run()->status);
    }
}
