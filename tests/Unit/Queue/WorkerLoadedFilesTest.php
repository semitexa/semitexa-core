<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\WorkerLoadedFiles;

/**
 * What worker:run loaded, and which of it changed since: a role started again
 * is forked with the loaded code, and this is what the supervisor's warning
 * is made of. A change it misses is a role silently running old code.
 */
final class WorkerLoadedFilesTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/semitexa-worker-loaded-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($this->file, "<?php\n// as loaded\n");
        touch($this->file, time() - 120);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->file . '.new');
    }

    #[Test]
    public function a_file_as_it_was_loaded_is_unchanged_and_an_edited_or_removed_one_is_not(): void
    {
        $loaded = new WorkerLoadedFiles([$this->file], time() - 60);
        self::assertSame([], $loaded->changed());

        touch($this->file, time() - 30);
        self::assertSame([], $loaded->changed(), 'touched, with the contents it had: not a change');

        file_put_contents($this->file, "<?php\n// edited\n");
        self::assertSame([$this->file], $loaded->changed());

        unlink($this->file);
        self::assertSame([$this->file], $loaded->changed());
    }

    /**
     * REGRESSION (review, core#256). The time and size were read, then the
     * hash. A file replaced between the two (a deploy writing while the worker
     * starts) was kept with the replacement's hash, so changed() compared the
     * replacement with itself and said nothing, while every role ran the
     * original. The replacement here even keeps the original's time, as an
     * rsync -t or a tar extraction does.
     */
    #[Test]
    public function a_file_replaced_while_it_was_being_hashed_is_reported_as_changed(): void
    {
        $file = $this->file;
        $original = (int) filemtime($file);
        $replaceThenHash = static function (string $path) use ($file, $original): string|false {
            file_put_contents($file . '.new', "<?php\n// the replacement, a deploy's\n");
            touch($file . '.new', $original);
            rename($file . '.new', $file);

            return hash_file('xxh128', $path);
        };

        $loaded = new WorkerLoadedFiles([$file], time() - 60, '', $replaceThenHash);

        self::assertStringContainsString('the replacement', (string) file_get_contents($file), 'it was replaced during the read');
        self::assertSame([$file], $loaded->changed(), 'what is on disk is not what was loaded');
    }

    /** Touched since the boot began: it may have been edited after it was loaded, so it is never vouched for. */
    #[Test]
    public function a_file_touched_since_the_boot_began_always_reads_as_changed(): void
    {
        touch($this->file, time());

        self::assertSame([$this->file], (new WorkerLoadedFiles([$this->file], time() - 5))->changed());
    }

    #[Test]
    public function compiled_caches_under_the_projects_var_are_not_code(): void
    {
        $root = sys_get_temp_dir() . '/semitexa-worker-loaded-root-' . bin2hex(random_bytes(6));
        mkdir($root . '/var/cache', 0o777, true);
        $cache = $root . '/var/cache/container.php';
        file_put_contents($cache, "<?php\n");
        touch($cache, time() - 120);

        try {
            $loaded = new WorkerLoadedFiles([$cache, $this->file], time() - 60, $root);
            file_put_contents($cache, "<?php\n// rebuilt\n");
            file_put_contents($this->file, "<?php\n// edited\n");

            self::assertSame([$this->file], $loaded->changed());
        } finally {
            @unlink($cache);
            @rmdir($root . '/var/cache');
            @rmdir($root . '/var');
            @rmdir($root);
        }
    }
}
