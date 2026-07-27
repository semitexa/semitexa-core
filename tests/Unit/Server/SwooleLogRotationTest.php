<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\SwooleLogRotation;

final class SwooleLogRotationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-rotation-' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function names_map_to_the_integers_swoole_expects(): void
    {
        self::assertSame(0, SwooleLogRotation::Single->swooleValue());
        self::assertSame(1, SwooleLogRotation::Monthly->swooleValue());
        self::assertSame(2, SwooleLogRotation::Daily->swooleValue());
        self::assertSame(3, SwooleLogRotation::Hourly->swooleValue());
        self::assertSame(4, SwooleLogRotation::EveryMinute->swooleValue());
    }

    #[Test]
    public function an_operator_copying_swooles_own_numeric_docs_still_gets_it_right(): void
    {
        self::assertSame(SwooleLogRotation::Daily, SwooleLogRotation::fromEnv('2'));
        self::assertSame(SwooleLogRotation::Single, SwooleLogRotation::fromEnv('0'));
        self::assertSame(SwooleLogRotation::Single, SwooleLogRotation::fromEnv('off'));
        self::assertSame(SwooleLogRotation::Daily, SwooleLogRotation::fromEnv('  DAILY '));
    }

    /**
     * A typo must not silently disable rotation — that is the failure this whole
     * change exists to prevent, and it would look identical to working config.
     */
    #[Test]
    public function an_unknown_value_fails_loudly_instead_of_disabling_rotation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SWOOLE_LOG_ROTATION "weekly"');

        SwooleLogRotation::fromEnv('weekly');
    }

    #[Test]
    public function only_single_counts_as_disabled(): void
    {
        self::assertFalse(SwooleLogRotation::Single->isEnabled());
        self::assertTrue(SwooleLogRotation::Daily->isEnabled());
    }

    /**
     * Verified against Swoole 6.2.1: with rotation on, it writes to
     * '<log_file>.<date>' and leaves '<log_file>' as an empty placeholder.
     */
    #[Test]
    public function the_active_file_is_the_newest_rotated_one_not_the_placeholder(): void
    {
        $base = $this->dir . '/swoole.log';
        touch($base);
        file_put_contents($base . '.20260725', "old\n");
        file_put_contents($base . '.20260727', "current\n");

        self::assertSame($base . '.20260727', SwooleLogRotation::resolveActiveFile($base));
    }

    /**
     * Self-review finding: the newest name wins a lexical sort, so a sibling an
     * operator's own logrotate left behind ('swoole.log.zip' sorts after any date)
     * would have been served as the live log.
     */
    #[Test]
    public function foreign_siblings_are_never_mistaken_for_the_active_log(): void
    {
        $base = $this->dir . '/swoole.log';
        touch($base);
        file_put_contents($base . '.20260727', "real\n");
        file_put_contents($base . '.zip', "not a log\n");
        file_put_contents($base . '.1.gz', "also not\n");

        self::assertSame($base . '.20260727', SwooleLogRotation::resolveActiveFile($base));
    }

    #[Test]
    public function only_foreign_siblings_means_no_rotated_file_at_all(): void
    {
        $base = $this->dir . '/swoole.log';
        file_put_contents($base, "live\n");
        file_put_contents($base . '.zip', "not a log\n");

        self::assertSame($base, SwooleLogRotation::resolveActiveFile($base));
    }

    /**
     * Review finding on PR #90: glob() returns directories too, and returning one
     * would make every later filemtime() call warn on a path that can never be read.
     */
    #[Test]
    public function a_directory_with_a_matching_name_is_not_returned_as_the_log(): void
    {
        $base = $this->dir . '/swoole.log';
        file_put_contents($base, "live\n");
        mkdir($base . '.20260727');

        self::assertSame($base, SwooleLogRotation::resolveActiveFile($base));

        rmdir($base . '.20260727');
    }

    #[Test]
    public function without_rotation_the_configured_path_is_used_unchanged(): void
    {
        $base = $this->dir . '/swoole.log';
        file_put_contents($base, "content\n");

        self::assertSame($base, SwooleLogRotation::resolveActiveFile($base));
    }

    /**
     * Turning rotation back off leaves the old dated files on disk. The reader must
     * follow whichever file is actually being written, not the newest name.
     */
    #[Test]
    public function a_reenabled_plain_log_wins_when_it_is_the_one_being_written(): void
    {
        $base = $this->dir . '/swoole.log';
        file_put_contents($base . '.20260727', "rotated\n");
        touch($base . '.20260727', time() - 3600);
        file_put_contents($base, "live again\n");
        touch($base, time());

        self::assertSame($base, SwooleLogRotation::resolveActiveFile($base));
    }
}
