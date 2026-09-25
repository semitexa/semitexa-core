<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Support;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Puts the runtime fixtures under tests/Fixtures (routes, handlers, an auth
 * handler, grant providers) in front of discovery for the length of a test
 * class, so the lifecycle and auth smoke tests run against a real
 * Application in any app that installs semitexa/core — not only in the
 * monorepo, whose demo modules they used to borrow.
 *
 * Discovery deliberately keeps every `\Tests\` class and every file under
 * `/tests/` out of the runtime classmap, so a fixture cannot reach it by being
 * autoloadable. It is added to the process-wide classmap discovery already
 * built, and the container is rebuilt so every registry reads the new map;
 * {@see uninstall()} takes both back out, so the rest of the suite sees the
 * app exactly as it was.
 */
final class FixtureModules
{
    private const DIRS = [
        'Semitexa\\Core\\Tests\\Fixtures\\AuthDemo\\' => __DIR__ . '/../Fixtures/AuthDemo',
        'Semitexa\\Core\\Tests\\Fixtures\\WebhookDemo\\' => __DIR__ . '/../Fixtures/WebhookDemo',
    ];

    /** @var array<class-string, string>|null */
    private static ?array $installed = null;

    public static function install(): void
    {
        if (self::$installed !== null) {
            return;
        }

        (new ClassDiscovery())->initialize();
        $classes = self::fixtureClasses();
        $root = ProjectRoot::get();

        \Closure::bind(static function () use ($root, $classes): void {
            ClassDiscovery::$sharedClassMaps[$root] = $classes + (ClassDiscovery::$sharedClassMaps[$root] ?? []);
            unset(ClassDiscovery::$sharedAttributeCaches[$root]);
        }, null, ClassDiscovery::class)();

        self::$installed = $classes;
        self::rebuildContainer();
    }

    public static function uninstall(): void
    {
        if (self::$installed === null) {
            return;
        }

        $classes = self::$installed;
        $root = ProjectRoot::get();

        \Closure::bind(static function () use ($root, $classes): void {
            ClassDiscovery::$sharedClassMaps[$root] = array_diff_key(ClassDiscovery::$sharedClassMaps[$root] ?? [], $classes);
            unset(ClassDiscovery::$sharedAttributeCaches[$root]);
        }, null, ClassDiscovery::class)();

        self::$installed = null;
        self::rebuildContainer();
    }

    /**
     * The container is built once per process and ContainerFactory::reset()
     * is a no-op by design (a worker never rebuilds). Tests that change what
     * discovery sees have to drop it themselves.
     */
    private static function rebuildContainer(): void
    {
        \Closure::bind(static function (): void {
            ContainerFactory::$container = null;
        }, null, ContainerFactory::class)();
    }

    /** @return array<class-string, string> */
    private static function fixtureClasses(): array
    {
        $classes = [];
        foreach (self::DIRS as $namespace => $dir) {
            $base = realpath($dir);
            if ($base === false) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($base) + 1, -4);
                /** @var class-string $class */
                $class = $namespace . str_replace('/', '\\', $relative);
                if (!class_exists($class) && !enum_exists($class)) {
                    continue;
                }
                $classes[$class] = $file->getPathname();
            }
        }
        ksort($classes);

        return $classes;
    }
}
