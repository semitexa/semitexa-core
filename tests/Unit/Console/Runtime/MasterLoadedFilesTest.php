<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console\Runtime;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Console\Runtime\MasterLoadedFiles;

/**
 * A reload that cannot apply a change says so (tk-server-reload-keeps-route-access).
 * Measured 2026-11-12: a string added to a handler stayed absent after
 * server:reload printed "reloaded successfully"; server:restart app served it.
 */
final class MasterLoadedFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-master-files-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/var/cache', 0o775, true);
        foreach (['src/Handler.php', 'src/Payload.php', 'var/cache/compiled.php'] as $file) {
            file_put_contents($this->root . '/' . $file, "<?php\n");
            touch($this->root . '/' . $file, 1_000);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_file_the_master_loaded_and_that_changed_since_is_named(): void
    {
        self::assertTrue(MasterLoadedFiles::record($this->root, $this->loaded(), 2_000));
        self::assertSame([], MasterLoadedFiles::changedSince($this->root), 'nothing changed since the master started');

        file_put_contents($this->root . '/src/Handler.php', "<?php // edited\n");

        self::assertSame([$this->root . '/src/Handler.php'], MasterLoadedFiles::changedSince($this->root));
    }

    /** Review, core#231: a time in whole seconds misses an edit in the start's second, or a file put back with an old time. */
    #[Test]
    public function a_change_is_seen_by_its_contents_whatever_its_time(): void
    {
        MasterLoadedFiles::record($this->root, $this->loaded(), 2_000);
        file_put_contents($this->root . '/src/Handler.php', "<?php // same second\n");
        touch($this->root . '/src/Handler.php', 2_000);
        file_put_contents($this->root . '/src/Payload.php', "<?php // restored from an old copy\n");
        touch($this->root . '/src/Payload.php', 500);

        self::assertSame([$this->root . '/src/Handler.php', $this->root . '/src/Payload.php'], MasterLoadedFiles::changedSince($this->root));
    }

    /** Review, core#231: an edit during the boot, after the master loaded the file, is not taken for what it holds. */
    #[Test]
    public function a_file_touched_while_the_master_booted_is_never_taken_as_unchanged(): void
    {
        file_put_contents($this->root . '/src/Handler.php', "<?php // edited after it was loaded\n");
        touch($this->root . '/src/Handler.php', 1_500);

        MasterLoadedFiles::record($this->root, $this->loaded(), 2_000, bootStartedAt: 1_500);

        self::assertSame([$this->root . '/src/Handler.php'], MasterLoadedFiles::changedSince($this->root), 'the disk is not proof of what the master holds');
    }

    #[Test]
    public function a_removed_file_counts_and_compiled_caches_do_not(): void
    {
        MasterLoadedFiles::record($this->root, $this->loaded(), 2_000);
        file_put_contents($this->root . '/var/cache/compiled.php', "<?php // recompiled\n");
        unlink($this->root . '/src/Payload.php');

        self::assertSame([$this->root . '/src/Payload.php'], MasterLoadedFiles::changedSince($this->root), 'a cache a reload rebuilds is not in the way');
    }

    #[Test]
    public function no_record_or_an_unreadable_one_is_unknown(): void
    {
        self::assertNull(MasterLoadedFiles::changedSince($this->root), 'Fable review, core#231: a master started before the record existed may hold code since updated');

        mkdir(dirname($this->root . '/' . MasterLoadedFiles::RELATIVE_PATH), 0o775, true);
        file_put_contents($this->root . '/' . MasterLoadedFiles::RELATIVE_PATH, 'not json');
        self::assertNull(MasterLoadedFiles::changedSince($this->root), 'review, core#231: a record that cannot be read is not a clean one');
    }

    /** Review, core#231: a record that could not be written is said, and leaves nothing that reads as clean. */
    #[Test]
    public function a_record_that_cannot_be_written_is_reported(): void
    {
        mkdir($this->root . '/var/run/master-loaded-files.json', 0o775, true); // a directory where the file belongs

        self::assertFalse(MasterLoadedFiles::record($this->root, $this->loaded(), 2_000));
        self::assertNull(MasterLoadedFiles::changedSince($this->root));
    }

    #[Test]
    public function a_previous_masters_record_is_not_this_ones(): void
    {
        MasterLoadedFiles::record($this->root, $this->loaded(), 2_000);

        self::assertSame([], MasterLoadedFiles::changedSince($this->root, masterStartedAt: 2_030), 'written just before this master forked');
        self::assertNull(MasterLoadedFiles::changedSince($this->root, masterStartedAt: 9_000), 'left by an earlier boot');
    }

    /** @return list<string> */
    private function loaded(): array
    {
        return [$this->root . '/src/Handler.php', $this->root . '/src/Payload.php', $this->root . '/var/cache/compiled.php'];
    }
}
