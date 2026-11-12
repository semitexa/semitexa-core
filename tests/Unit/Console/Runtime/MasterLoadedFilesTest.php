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
        MasterLoadedFiles::record($this->root, $this->loaded(), 2_000);
        self::assertSame([], MasterLoadedFiles::changedSince($this->root), 'nothing changed since the master started');

        touch($this->root . '/src/Handler.php', 3_000);

        self::assertSame([$this->root . '/src/Handler.php'], MasterLoadedFiles::changedSince($this->root));
    }

    #[Test]
    public function a_removed_file_counts_and_compiled_caches_do_not(): void
    {
        MasterLoadedFiles::record($this->root, $this->loaded(), 2_000);
        touch($this->root . '/var/cache/compiled.php', 3_000);
        unlink($this->root . '/src/Payload.php');

        self::assertSame([$this->root . '/src/Payload.php'], MasterLoadedFiles::changedSince($this->root), 'a cache a reload rebuilds is not in the way');
    }

    #[Test]
    public function with_nothing_recorded_a_reload_is_not_held_back(): void
    {
        self::assertSame([], MasterLoadedFiles::changedSince($this->root), 'an older server that wrote no record');

        mkdir(dirname($this->root . '/' . MasterLoadedFiles::RELATIVE_PATH), 0o775, true);
        file_put_contents($this->root . '/' . MasterLoadedFiles::RELATIVE_PATH, 'not json');
        self::assertSame([], MasterLoadedFiles::changedSince($this->root));
    }

    /** @return list<string> */
    private function loaded(): array
    {
        return [$this->root . '/src/Handler.php', $this->root . '/src/Payload.php', $this->root . '/var/cache/compiled.php'];
    }
}
