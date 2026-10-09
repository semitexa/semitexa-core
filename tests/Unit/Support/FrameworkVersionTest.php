<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\FrameworkVersion;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The footer version: the release the updater recorded wins over core's own
 * tag, because a cut that does not re-tag core still has to show its number.
 * Each case runs against a throwaway project root.
 */
final class FrameworkVersionTest extends TestCase
{
    private string $root;

    private string|false $cwd;

    private string|false $env;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/framework-version-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/modules', 0o777, true);
        file_put_contents($this->root . '/composer.json', '{}');

        $this->cwd = getcwd();
        $this->env = getenv('SEMITEXA_RELEASE_VERSION');
        putenv('SEMITEXA_RELEASE_VERSION');
        chdir($this->root);
        ProjectRoot::reset();
        FrameworkVersion::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== false) {
            chdir($this->cwd);
        }
        putenv($this->env === false ? 'SEMITEXA_RELEASE_VERSION' : 'SEMITEXA_RELEASE_VERSION=' . $this->env);
        ProjectRoot::reset();
        FrameworkVersion::reset();
        $this->removeTree($this->root);
    }

    #[Test]
    public function the_recorded_release_is_what_runs(): void
    {
        FrameworkVersion::record('2026.10.09.0714');

        self::assertSame('2026.10.09.0714', FrameworkVersion::current());
        $data = json_decode((string) file_get_contents($this->root . '/' . FrameworkVersion::RECORD_PATH), true);
        self::assertSame('semitexa/ultimate', $data['package']);
    }

    #[Test]
    public function a_new_record_is_seen_without_a_restart(): void
    {
        FrameworkVersion::record('2026.10.08.0620');
        self::assertSame('2026.10.08.0620', FrameworkVersion::current());

        FrameworkVersion::record('2026.10.09.0714');

        self::assertSame('2026.10.09.0714', FrameworkVersion::current());
    }

    #[Test]
    public function the_environment_override_wins_over_the_record(): void
    {
        FrameworkVersion::record('2026.10.09.0714');
        putenv('SEMITEXA_RELEASE_VERSION=2026.11.01.0000');
        FrameworkVersion::reset();

        self::assertSame('2026.11.01.0000', FrameworkVersion::current());
    }

    #[Test]
    public function a_forgotten_record_falls_back_to_the_core_tag(): void
    {
        $coreTag = FrameworkVersion::current();
        FrameworkVersion::record('2026.10.09.0714');
        self::assertSame('2026.10.09.0714', FrameworkVersion::current());

        FrameworkVersion::forget();

        self::assertSame($coreTag, FrameworkVersion::current());
        self::assertFileDoesNotExist($this->root . '/' . FrameworkVersion::RECORD_PATH);
    }

    #[Test]
    public function a_record_that_is_not_a_release_is_ignored(): void
    {
        $coreTag = FrameworkVersion::current();
        mkdir($this->root . '/var/run', 0o777, true);
        file_put_contents($this->root . '/' . FrameworkVersion::RECORD_PATH, '{"version":"dev-develop"}');

        self::assertSame($coreTag, FrameworkVersion::current());
    }

    #[Test]
    public function recording_a_version_that_is_not_a_release_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FrameworkVersion::record('dev-master');
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
