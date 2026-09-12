<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;

/**
 * Whether this build of Swoole can compress a response at all.
 *
 * `http_compression` is ON by default — the Server constructor sets it — so an
 * application that never mentions compression still expects it. But the setting
 * is only honoured where the extension was COMPILED with zlib, brotli or zstd,
 * and Swoole's configure detects zlib silently: `AC_CHECK_LIB(z, gzgets)` needs
 * no flag and raises no error when the header is absent. An image built without
 * `zlib-dev` therefore produces an extension with every compression path
 * removed, while the setting that would use it is accepted and does nothing.
 *
 * Nothing reports that, because there is nothing to report: no warning, no
 * failed request, no log line. It shows up only as responses that are five
 * times larger than they should be, which nobody attributes to a missing build
 * dependency. MEASURED in the framework workspace on exactly such an image:
 * `GET /` returned 284290 bytes uncompressed where the same response is 52655
 * with gzip — 231 KB per request, invisible.
 *
 * It is a warning rather than a failure: the application serves correctly, it
 * just serves heavy. The fix is a rebuild, not a code change, which is why the
 * hint says so — a package upgrade alone will never clear this.
 */
#[AsDoctorCheck(name: 'http.response-compression', package: 'semitexa/core')]
final class SwooleCompressionDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        if (!extension_loaded('swoole')) {
            return DoctorResult::skip('ext-swoole is not loaded; response compression is not this runtime\'s to answer for.');
        }

        $available = [];
        foreach (['gzip' => 'SWOOLE_HAVE_ZLIB', 'brotli' => 'SWOOLE_HAVE_BROTLI', 'zstd' => 'SWOOLE_HAVE_ZSTD'] as $name => $constant) {
            if (defined($constant)) {
                $available[] = $name;
            }
        }

        // Registered under #ifdef SW_HAVE_COMPRESSION, which is itself defined
        // only when one of the three libraries was found at build time. Its
        // ABSENCE is the diagnosis — there is no runtime setting to inspect.
        // VERIFIED against a real image built without zlib-dev: both this
        // constant and SWOOLE_HAVE_ZLIB are simply not there.
        return self::verdict(defined('SWOOLE_HAVE_COMPRESSION'), $available);
    }

    /**
     * The verdict, given what the build actually has.
     *
     * Separated from the constants it reads so it can be exercised for a build
     * this machine does not have. A check whose unhealthy branch has never run
     * is a check that has never been tested, and this one's whole purpose is
     * the branch that fires on somebody else's image.
     *
     * @param list<string> $available encodings compiled in, e.g. ['gzip']
     */
    public static function verdict(bool $compiledWithCompression, array $available): DoctorResult
    {
        if (!$compiledWithCompression) {
            return DoctorResult::warn(
                'Swoole was built without compression support, so every response goes out at full size '
                . 'however the client asks. `http_compression` is on by default and is accepted and inert '
                . 'on this build — measured on one such image, a 284 KB page stayed 284 KB where gzip '
                . 'takes it to 52 KB.',
                'Add `zlib-dev` to the apk line in your Dockerfile — Swoole detects zlib automatically, so '
                . 'no configure flag is needed — then rebuild the image and restart. Upgrading Semitexa '
                . 'packages alone will not fix this: the missing part is in the built extension, not in '
                . 'the code.',
            );
        }

        // The compression macro is set by any of the three, but gzip is the one
        // every client accepts; brotli or zstd alone would leave most requests
        // uncompressed in practice.
        if (!in_array('gzip', $available, true)) {
            return DoctorResult::warn(
                'Swoole has compression support but not gzip (built with: ' . implode(', ', $available) . '). '
                . 'Almost every client advertises gzip and nothing else universally, so most responses will '
                . 'still go out uncompressed.',
                'Add `zlib-dev` to the apk line in your Dockerfile and rebuild the image.',
            );
        }

        return DoctorResult::pass('Response compression available (' . implode(', ', $available) . ').');
    }
}
