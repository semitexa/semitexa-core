<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\AppLogRotation;
use Semitexa\Core\Server\SwooleLogRetention;

/**
 * The half of log hygiene that was missing: app.log had no ceiling at all while
 * swoole.log — measured at 1.2 MB and untouched for five weeks — had both a rotation
 * policy and a retention window.
 *
 * The composition with {@see SwooleLogRetention} is what these tests care about most.
 * Rotation names files and retention deletes them, and they agree only because the
 * suffix format lands inside retention's sibling guard. That agreement is invisible in
 * both classes and would break silently, so it is pinned here.
 */
final class AppLogRotationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-applog-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function it_rotates_only_once_the_ceiling_is_reached(): void
    {
        $rotation = new AppLogRotation(maxBytes: 100);

        self::assertFalse($rotation->shouldRotate(99));
        self::assertTrue($rotation->shouldRotate(100), 'the ceiling is inclusive');
        self::assertTrue($rotation->shouldRotate(360_000_000));
    }

    #[Test]
    public function a_zero_ceiling_means_unbounded_and_never_touches_the_file(): void
    {
        $path = $this->dir . '/app.log';
        file_put_contents($path, str_repeat('x', 4096));
        $rotation = new AppLogRotation(maxBytes: AppLogRotation::DISABLED);

        self::assertFalse($rotation->isEnabled());
        self::assertFalse($rotation->shouldRotate(\PHP_INT_MAX));
        self::assertNull($rotation->rotate($path));
        self::assertFileExists($path);
    }

    #[Test]
    public function rotating_moves_the_file_aside_and_frees_the_name(): void
    {
        $path = $this->dir . '/app.log';
        file_put_contents($path, "line\n");
        $rotation = new AppLogRotation(maxBytes: 1);

        $target = $rotation->rotate($path, 1_767_225_600);

        self::assertNotNull($target);
        self::assertFileDoesNotExist($path, 'the live name must be free for the next append');
        self::assertSame("line\n", file_get_contents($target));
    }

    #[Test]
    public function a_rotated_file_stays_recognisable_to_the_retention_pruner(): void
    {
        // The contract that makes rotation and retention compose. If the suffix ever
        // stops matching, rotation keeps working and pruning silently stops — the exact
        // shape of the bug this epic exists for.
        $path = $this->dir . '/app.log';
        file_put_contents($path, 'x');
        $now = 1_767_225_600;

        $target = (new AppLogRotation(maxBytes: 1))->rotate($path, $now);
        self::assertNotNull($target);

        $suffix = substr($target, strlen($path) + 1);
        self::assertMatchesRegularExpression('/^\d{6,14}$/', $suffix, 'must match SwooleLogRetention::prune()');

        touch($target, $now - (30 * 86400));
        $removed = (new SwooleLogRetention($path, 14))->prune($now);

        self::assertSame([$target], $removed, 'an aged-out slice is actually collected');
    }

    #[Test]
    public function two_rotations_inside_one_second_do_not_overwrite_each_other(): void
    {
        // A burst can cross the ceiling twice in the same second. Losing the first slice
        // would delete precisely the lines explaining the burst.
        $path = $this->dir . '/app.log';
        $now = 1_767_225_600;

        file_put_contents($path, 'first');
        $one = (new AppLogRotation(maxBytes: 1))->rotate($path, $now);
        file_put_contents($path, 'second');
        $two = (new AppLogRotation(maxBytes: 1))->rotate($path, $now);

        self::assertNotSame($one, $two);
        self::assertSame('first', file_get_contents((string) $one));
        self::assertSame('second', file_get_contents((string) $two));
        self::assertMatchesRegularExpression('/^\d{6,14}$/', substr((string) $two, strlen($path) + 1));
    }

    #[Test]
    public function an_exhausted_collision_window_refuses_to_rotate_rather_than_overwrite(): void
    {
        // Review finding: POSIX rename() REPLACES an existing destination, so the old
        // fallback to the base path silently destroyed an already-rotated slice. Refusing
        // costs one oversized file, which is the cheaper failure by far.
        $rotation = new AppLogRotation(maxBytes: 1);

        $target = $rotation->rotatedPath($this->dir . '/app.log', 1_767_225_600, static fn (): bool => true);

        self::assertNull($target, 'no free name means no rotation, never an overwrite');
    }

    #[Test]
    public function the_collision_search_walks_forward_until_it_finds_a_free_slot(): void
    {
        $path = $this->dir . '/app.log';
        $now = 1_767_225_600;
        $taken = [];
        for ($offset = 0; $offset < 120; ++$offset) {
            $taken[$path . '.' . date(AppLogRotation::SUFFIX_FORMAT, $now + $offset)] = true;
        }

        $target = (new AppLogRotation(maxBytes: 1))
            ->rotatedPath($path, $now, static fn (string $c): bool => isset($taken[$c]));

        // Past the old 60-second window, and still a suffix the pruner will recognise.
        self::assertNotNull($target);
        self::assertSame($path . '.' . date(AppLogRotation::SUFFIX_FORMAT, $now + 120), $target);
        self::assertMatchesRegularExpression('/^\d{6,14}$/', substr($target, strlen($path) + 1));
    }

    #[Test]
    public function a_malformed_ceiling_falls_back_instead_of_rotating_every_write(): void
    {
        // '32e6' casts to 32 under is_numeric-style parsing, which would rotate the log
        // on essentially every flush.
        self::assertSame(AppLogRotation::DEFAULT_MAX_BYTES, AppLogRotation::maxBytesFromEnv('32e6'));
        self::assertSame(AppLogRotation::DEFAULT_MAX_BYTES, AppLogRotation::maxBytesFromEnv(null));
        self::assertSame(AppLogRotation::DEFAULT_MAX_BYTES, AppLogRotation::maxBytesFromEnv('plenty'));
        self::assertSame(1024, AppLogRotation::maxBytesFromEnv('1024'));
        self::assertSame(AppLogRotation::DISABLED, AppLogRotation::maxBytesFromEnv('0'), 'an explicit 0 is a choice');
    }
}
