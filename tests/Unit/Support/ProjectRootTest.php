<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The last-resort resolution, reached when neither the CWD nor any ancestor
 * of the package looks like an app (composer.json + src/modules) — a project
 * with no modules yet, or a process started outside the project.
 *
 * The real ProjectRoot.php is copied into a throwaway tree and resolved in a
 * child process, because the fallback is anchored on the file's own __DIR__.
 */
final class ProjectRootTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/project-root-test-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/elsewhere', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    #[Test]
    public function an_installed_package_falls_back_to_the_project_root_not_vendor(): void
    {
        $project = $this->base . '/project';
        $this->touch($project . '/composer.json');
        $this->touch($project . '/vendor/autoload.php');
        $this->touch($project . '/vendor/semitexa/core/composer.json');

        self::assertSame($project, $this->resolveFrom($project . '/vendor/semitexa/core'));
    }

    #[Test]
    public function the_package_own_checkout_falls_back_to_the_checkout_root(): void
    {
        $checkout = $this->base . '/semitexa-core';
        $this->touch($checkout . '/composer.json');
        $this->touch($checkout . '/vendor/autoload.php');

        self::assertSame($checkout, $this->resolveFrom($checkout));
    }

    /** Copies ProjectRoot.php into <package>/src/Support and resolves it from an unrelated CWD. */
    private function resolveFrom(string $packageRoot): string
    {
        $file = $packageRoot . '/src/Support/ProjectRoot.php';
        $source = (new \ReflectionClass(ProjectRoot::class))->getFileName();
        self::assertIsString($source);
        @mkdir(dirname($file), 0o777, true);
        // The copy must not find a real project before the fallback under test
        // runs: step 1 also tries a fixed /var/www/html, and inside the app and
        // test containers that IS a project, so the resolution never reached
        // the fallback there and these assertions failed on every CI box.
        $code = (string) file_get_contents($source);
        $isolated = str_replace("'/var/www/html'", var_export($this->base . '/no-such-host-root', true), $code, $replaced);
        self::assertSame(1, $replaced, 'ProjectRoot.php no longer names /var/www/html; update this isolation');
        file_put_contents($file, $isolated);

        $code = sprintf('require %s; echo \\Semitexa\\Core\\Support\\ProjectRoot::get();', var_export($file, true));
        $process = proc_open(
            [PHP_BINARY, '-n', '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->base . '/elsewhere',
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }

    private function touch(string $path): void
    {
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, '');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
