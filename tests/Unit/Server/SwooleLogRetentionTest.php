<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\SwooleLogRetention;

final class SwooleLogRetentionTest extends TestCase
{
    private string $dir;
    private string $base;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-retention-' . uniqid();
        mkdir($this->dir, 0775, true);
        $this->base = $this->dir . '/swoole.log';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function write(string $suffix, int $ageDays): string
    {
        $path = $suffix === '' ? $this->base : $this->base . '.' . $suffix;
        file_put_contents($path, "x\n");
        touch($path, time() - ($ageDays * 86400));

        return $path;
    }

    #[Test]
    public function files_past_the_window_go_and_recent_ones_stay(): void
    {
        $old    = $this->write('20260701', 20);
        $recent = $this->write('20260726', 1);

        $removed = (new SwooleLogRetention($this->base, 14))->prune();

        self::assertSame([$old], $removed);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($recent);
    }

    /**
     * The live log carries no date suffix. Deleting it would take out the file the
     * running server has open — the opposite of the intent.
     */
    #[Test]
    public function the_active_log_itself_is_never_deleted(): void
    {
        $live = $this->write('', 400);

        self::assertSame([], (new SwooleLogRetention($this->base, 14))->prune());
        self::assertFileExists($live);
    }

    /**
     * An operator's own logrotate may drop swoole.log.1.gz next to ours. Those are
     * not ours to delete, and a bare '.*' glob would have taken them.
     */
    #[Test]
    public function foreign_files_sharing_the_prefix_are_left_alone(): void
    {
        $foreign = $this->write('1.gz', 400);
        $ours    = $this->write('20260101', 400);

        $removed = (new SwooleLogRetention($this->base, 14))->prune();

        self::assertSame([$ours], $removed);
        self::assertFileExists($foreign);
    }

    #[Test]
    public function a_zero_or_negative_window_disables_pruning_entirely(): void
    {
        $ancient = $this->write('20250101', 900);

        self::assertSame([], (new SwooleLogRetention($this->base, 0))->prune());
        self::assertSame([], (new SwooleLogRetention($this->base, -5))->prune());
        self::assertFileExists($ancient);
    }

    #[Test]
    public function the_cutoff_is_evaluated_against_the_supplied_clock(): void
    {
        $path = $this->write('20260720', 5);

        // Five days old, so a 14-day window keeps it...
        self::assertSame([], (new SwooleLogRetention($this->base, 14))->prune());
        // ...and the same file is stale once "now" is a fortnight later.
        self::assertSame([$path], (new SwooleLogRetention($this->base, 14))->prune(time() + (14 * 86400)));
    }
}
