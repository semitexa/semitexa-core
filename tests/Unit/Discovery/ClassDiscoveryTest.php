<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;

final class ClassDiscoveryTest extends TestCase
{
    #[Test]
    public function initialization_skips_unreadable_psr4_children(): void
    {
        $paths = $this->createTempProjectRoot();
        $root = $paths['root'];
        $brokenDir = $paths['brokenDir'];

        try {
            ProjectRoot::reset();
            $this->setProjectRoot($root);

            if (!@chmod($brokenDir, 0000) || is_readable($brokenDir)) {
                self::markTestSkipped('Unreadable child directories cannot be enforced on this platform.');
            }

            $discovery = new ClassDiscovery();
            $classMap = $discovery->getClassMap();

            self::assertArrayHasKey('Semitexa\\Fixture\\GoodCommand', $classMap);
            self::assertArrayNotHasKey('Semitexa\\Fixture\\Broken\\BadCommand', $classMap);
        } finally {
            @chmod($brokenDir, 0777);
            ProjectRoot::reset();
            $this->removeDirectory($root);
        }
    }

    #[Test]
    public function dev_only_fqcns_are_filtered_out_of_classmap(): void
    {
        $root = $this->createDevOnlyFixtureRoot();

        try {
            ProjectRoot::reset();
            $this->setProjectRoot($root);

            $discovery = new ClassDiscovery();
            $classMap = $discovery->getClassMap();

            self::assertArrayHasKey('Semitexa\\Fixture\\RuntimeService', $classMap);
            self::assertArrayNotHasKey('Semitexa\\Fixture\\PHPStan\\Rules\\SomeRule', $classMap);
            self::assertArrayNotHasKey('Semitexa\\Fixture\\Testing\\PhpUnitExtension', $classMap);
            self::assertArrayNotHasKey('Semitexa\\Fixture\\Tests\\Unit\\SomeTest', $classMap);
            self::assertArrayNotHasKey('Semitexa\\Fixture\\Composer\\InstallPlugin', $classMap);
        } finally {
            ProjectRoot::reset();
            $this->removeDirectory($root);
        }
    }

    /**
     * @return array{root: string, brokenDir: string}
     */
    private function createTempProjectRoot(): array
    {
        $root = sys_get_temp_dir() . '/semitexa-class-discovery-' . uniqid('', true);
        $fixtureDir = $root . '/src/Fixture';
        $brokenDir = $fixtureDir . '/Broken';
        $composerDir = $root . '/vendor/composer';

        mkdir($root . '/src/modules', 0755, true);
        mkdir($fixtureDir, 0755, true);
        mkdir($brokenDir, 0755, true);
        mkdir($composerDir, 0755, true);

        file_put_contents($root . '/composer.json', "{}\n");
        file_put_contents($composerDir . '/autoload_classmap.php', "<?php\nreturn [];\n");
        file_put_contents(
            $composerDir . '/autoload_psr4.php',
            "<?php\nreturn [\n    'Semitexa\\\\Fixture\\\\' => [__DIR__ . '/../../src/Fixture'],\n];\n",
        );
        file_put_contents(
            $fixtureDir . '/GoodCommand.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Semitexa\Fixture;
final class GoodCommand
{
}
PHP,
        );
        file_put_contents(
            $brokenDir . '/BadCommand.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Semitexa\Fixture\Broken;
final class BadCommand
{
}
PHP,
        );

        return [
            'root' => $root,
            'brokenDir' => $brokenDir,
        ];
    }

    private function createDevOnlyFixtureRoot(): string
    {
        $root = sys_get_temp_dir() . '/semitexa-class-discovery-devonly-' . uniqid('', true);
        $fixtureDir = $root . '/src/Fixture';
        $composerDir = $root . '/vendor/composer';

        mkdir($root . '/src/modules', 0755, true);
        mkdir($fixtureDir . '/PHPStan/Rules', 0755, true);
        mkdir($fixtureDir . '/Testing', 0755, true);
        mkdir($fixtureDir . '/Tests/Unit', 0755, true);
        mkdir($fixtureDir . '/Composer', 0755, true);
        mkdir($composerDir, 0755, true);

        file_put_contents($root . '/composer.json', "{}\n");
        file_put_contents($composerDir . '/autoload_classmap.php', "<?php\nreturn [];\n");
        file_put_contents(
            $composerDir . '/autoload_psr4.php',
            "<?php\nreturn [\n    'Semitexa\\\\Fixture\\\\' => [__DIR__ . '/../../src/Fixture'],\n];\n",
        );

        $fixtures = [
            $fixtureDir . '/RuntimeService.php' => ['Semitexa\\Fixture', 'RuntimeService'],
            $fixtureDir . '/PHPStan/Rules/SomeRule.php' => ['Semitexa\\Fixture\\PHPStan\\Rules', 'SomeRule'],
            $fixtureDir . '/Testing/PhpUnitExtension.php' => ['Semitexa\\Fixture\\Testing', 'PhpUnitExtension'],
            $fixtureDir . '/Tests/Unit/SomeTest.php' => ['Semitexa\\Fixture\\Tests\\Unit', 'SomeTest'],
            $fixtureDir . '/Composer/InstallPlugin.php' => ['Semitexa\\Fixture\\Composer', 'InstallPlugin'],
        ];

        foreach ($fixtures as $path => [$namespace, $class]) {
            file_put_contents(
                $path,
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class {$class}\n{\n}\n",
            );
        }

        return $root;
    }

    private function setProjectRoot(string $root): void
    {
        $property = new \ReflectionProperty(ProjectRoot::class, 'root');
        $property->setAccessible(true);
        $property->setValue(null, $root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                @chmod($itemPath, 0777);
                @rmdir($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @chmod($path, 0777);
        @rmdir($path);
    }
}
