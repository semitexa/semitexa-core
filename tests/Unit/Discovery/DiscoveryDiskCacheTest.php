<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Application\Console\Command\CacheClearCommand;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\DiscoveryCacheStore;
use Semitexa\Core\Discovery\DiscoveryFileIndex;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What a walk of the sources found is kept in var/cache/discovery and read by the next process
 * instead of being worked out again (tk-discovery-registries-as-php-arrays): the walk reads only
 * the files that changed, and the classmap is a PHP file of strings that OPcache shares.
 * Attribute lookups are not stored: they are scanned by reflection in every process.
 *
 * Each test is a project of its own under the temp directory, with a namespace of its own, so
 * a class one test loaded is never the class another one expects to be absent.
 */
final class DiscoveryDiskCacheTest extends TestCase
{
    // verify:accept-test-change the attribute scan is no longer stored (core#257, scope cut after review round 2): the tests of the stored scan went with the code, the classmap's stay
    private string $root = '';

    private string $namespace = '';

    private ?BootDiagnostics $diagnostics = null;

    private mixed $projectRoot = null;

    /** @var array<string, mixed> */
    private array $discoveryState = [];

    /** @var list<array{0: \Composer\Autoload\ClassLoader, 1: array<string, mixed>}> */
    private array $composerLoaders = [];

    private const COMPOSER_LOADER_STATE = ['prefixLengthsPsr4', 'prefixDirsPsr4', 'classMap', 'missingClasses'];

    protected function setUp(): void
    {
        // Everything this test replaces in the process is put back as it was found, not reset:
        // the project root another test chose, what discovery had learned, and the prefixes a
        // walk of the fixture root adds to Composer's loader.
        $current = new ReflectionProperty(BootDiagnostics::class, 'current');
        $held = $current->getValue();
        $this->diagnostics = $held instanceof BootDiagnostics ? $held : null;
        $current->setValue(null, null);
        $this->projectRoot = (new ReflectionProperty(ProjectRoot::class, 'root'))->getValue();
        $this->discoveryState = (new \ReflectionClass(ClassDiscovery::class))->getStaticProperties();
        $this->composerLoaders = [];
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            $state = [];
            foreach (self::COMPOSER_LOADER_STATE as $property) {
                $state[$property] = (new ReflectionProperty($loader, $property))->getValue($loader);
            }
            $this->composerLoaders[] = [$loader, $state];
        }

        $suffix = bin2hex(random_bytes(5));
        $this->namespace = 'Semitexa\\DiskCache' . $suffix;
        $this->root = sys_get_temp_dir() . '/semitexa-discovery-disk-' . $suffix;
        mkdir($this->root . '/src/Fixture', 0o755, true);
        mkdir($this->root . '/vendor/composer', 0o755, true);
        mkdir($this->root . '/var', 0o755, true);
        file_put_contents($this->root . '/composer.json', "{}\n");
        file_put_contents($this->root . '/vendor/composer/autoload_classmap.php', "<?php\nreturn [];\n");
        file_put_contents(
            $this->root . '/vendor/composer/autoload_psr4.php',
            "<?php\nreturn [\n    " . var_export($this->namespace . '\\', true) . " => [__DIR__ . '/../../src/Fixture'],\n];\n",
        );
        $this->source('Marker', "#[\\Attribute]\nfinal class Marker\n{\n}\n");

        ProjectRoot::reset();
        ClassDiscovery::resetSharedCache();
        (new ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->root);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(BootDiagnostics::class, 'current'))->setValue(null, $this->diagnostics);
        (new ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, $this->projectRoot);
        foreach ($this->discoveryState as $property => $value) {
            (new ReflectionProperty(ClassDiscovery::class, $property))->setValue(null, $value);
        }
        foreach ($this->composerLoaders as [$loader, $state]) {
            foreach ($state as $property => $value) {
                (new ReflectionProperty($loader, $property))->setValue($loader, $value);
            }
        }
        if ($this->root !== '') {
            exec('chmod -R u+w ' . escapeshellarg($this->root) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->root));
        }
    }

    #[Test]
    public function an_unchanged_file_is_not_read_by_the_next_walk(): void
    {
        $file = $this->source('Alpha', "final class Alpha\n{\n}\n");
        $this->aSecondLater();
        self::assertArrayHasKey($this->namespace . '\\Alpha', $this->walk());

        // The table now says the file declares another class. The file is untouched, so only
        // reading it could contradict the table, and that is what the next walk must not do.
        $tables = glob($this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY . '/*/files.json');
        self::assertIsArray($tables);
        self::assertCount(1, $tables, 'the walk remembered nothing');
        $table = json_decode((string) file_get_contents($tables[0]), true);
        self::assertIsArray($table);
        self::assertIsArray($table['files'] ?? null);
        // Keyed by the path the walk met the file under, which Composer's map spells its own way.
        $keys = array_values(array_filter(array_keys($table['files']), static fn (int|string $path): bool => str_ends_with((string) $path, '/' . basename($file))));
        self::assertCount(1, $keys, 'the file is not in the table');
        self::assertIsString($table['files'][$keys[0]]);
        self::assertStringEndsWith(':' . $this->namespace . '\\Alpha', $table['files'][$keys[0]]);
        $table['files'][$keys[0]] = str_replace('\\Alpha', '\\FromTheTable', $table['files'][$keys[0]]);
        file_put_contents($tables[0], json_encode($table));

        $classMap = $this->walk();
        self::assertArrayHasKey($this->namespace . '\\FromTheTable', $classMap, 'the walk read a file that had not changed');
        self::assertArrayNotHasKey($this->namespace . '\\Alpha', $classMap);
    }

    #[Test]
    public function a_file_rewritten_under_the_same_mtime_and_size_is_read_again(): void
    {
        // What cp -p, rsync -a, tar and a Docker COPY do: the content changes, the mtime is the
        // old one. Only ctime, which no tool can set, says the file was written.
        $file = $this->source('Alpha', "final class Alpha\n{\n}\n");
        $mtime = (int) filemtime($file);
        $size = (int) filesize($file);
        $this->aSecondLater();
        self::assertArrayHasKey($this->namespace . '\\Alpha', $this->walk());

        file_put_contents($file, str_replace('Alpha', 'Omega', (string) file_get_contents($file)));
        touch($file, $mtime);
        clearstatcache();
        self::assertSame([$mtime, $size], [filemtime($file), filesize($file)]);

        $classMap = $this->walk();
        self::assertArrayHasKey($this->namespace . '\\Omega', $classMap, 'same mtime, same size: the walk took the file for unchanged');
        self::assertArrayNotHasKey($this->namespace . '\\Alpha', $classMap);
    }

    #[Test]
    public function an_edited_a_new_and_a_removed_file_are_all_seen_by_the_next_walk(): void
    {
        $edited = $this->source('Edited', "final class Edited\n{\n}\n");
        $removed = $this->source('Removed', "final class Removed\n{\n}\n");
        $this->walk();

        file_put_contents($edited, $this->php("final class Renamed\n{\n}\n"));
        touch($edited, time() - 50);
        unlink($removed);
        $this->source('Added', "final class Added\n{\n}\n");
        clearstatcache();

        $classes = array_keys($this->walk());
        self::assertContains($this->namespace . '\\Renamed', $classes, 'an edited file was answered from the cache');
        self::assertContains($this->namespace . '\\Added', $classes, 'a new file must appear without anything being cleared');
        self::assertNotContains($this->namespace . '\\Edited', $classes);
        self::assertNotContains($this->namespace . '\\Removed', $classes, 'a removed file stayed in the classmap');
    }

    #[Test]
    public function a_file_saved_in_the_second_of_the_walk_is_read_again(): void
    {
        // mtime has one-second resolution: a file as young as the walk that recorded it may
        // have changed since without showing it. In the future is "as young" for certain.
        $file = $this->source('Young', "final class Young\n{\n}\n");
        $mtime = time() + 5;
        touch($file, $mtime);
        clearstatcache();
        $this->walk();

        file_put_contents($file, str_replace('Young', 'Fresh', (string) file_get_contents($file)));
        touch($file, $mtime);
        clearstatcache();

        self::assertArrayHasKey($this->namespace . '\\Fresh', $this->walk(), 'same mtime, same size, different class: the file had to be read');
    }

    #[Test]
    public function the_classmap_is_a_php_file_of_strings_that_opcache_will_cache_at_once(): void
    {
        $this->source('Alpha', "final class Alpha\n{\n}\n");
        $classMap = $this->walk();

        $files = glob($this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY . '/*/classmap-*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files, 'the walk stored no classmap');

        $stored = require $files[0];
        self::assertSame($classMap, $stored);
        self::assertSame(DiscoveryCacheStore::hashOf($classMap), substr(basename($files[0], '.php'), strlen('classmap-')), 'named after its content, so it is never rewritten');
        // opcache.file_update_protection: a file younger than that is compiled but not cached,
        // and the array it returns then lives on the heap of every process that includes it.
        self::assertLessThan(time() - 86400 * 365, filemtime($files[0]));

        self::assertSame([], glob(dirname($files[0]) . '/*.tmp'), 'a temp file was left behind');
    }

    #[Test]
    public function a_changed_classmap_replaces_the_stored_one(): void
    {
        $this->source('Alpha', "final class Alpha\n{\n}\n");
        $this->walk();
        $this->source('Beta', "final class Beta\n{\n}\n");
        $classMap = $this->walk();

        $files = glob($this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY . '/*/classmap-*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files, 'the classmap it replaced must not pile up');
        self::assertSame($classMap, require $files[0]);
    }

    #[Test]
    public function a_file_that_could_not_be_read_is_read_again_by_the_next_walk(): void
    {
        $store = DiscoveryCacheStore::forProjectRoot($this->root);
        self::assertNotNull($store);
        $file = new \SplFileInfo($this->source('Alpha', "final class Alpha\n{\n}\n"));
        $this->aSecondLater();

        $failing = new DiscoveryFileIndex($store);
        self::assertNull($failing->declaredClass($file, static fn (string $path): false => false));
        $failing->save();

        $reads = 0;
        $read = function (string $path) use (&$reads): string {
            ++$reads;

            return $this->namespace . '\\Alpha';
        };
        $next = new DiscoveryFileIndex($store);
        self::assertSame($this->namespace . '\\Alpha', $next->declaredClass($file, $read), 'a failed read was remembered as a file that declares nothing');
        self::assertSame(1, $reads);
        $next->save();

        // And a read that worked IS remembered, or the assertion above would hold for a table
        // that remembers nothing at all.
        $this->aSecondLater();
        $settled = new DiscoveryFileIndex($store);
        self::assertSame($this->namespace . '\\Alpha', $settled->declaredClass($file, $read));
        self::assertSame(1, $reads, 'an unchanged file was read again');
    }

    #[Test]
    public function a_project_root_without_var_gets_no_cache(): void
    {
        rmdir($this->root . '/var');
        $this->source('Alpha', "final class Alpha\n{\n}\n");

        self::assertNull(DiscoveryCacheStore::forProjectRoot($this->root));
        self::assertArrayHasKey($this->namespace . '\\Alpha', $this->walk());
        (new ClassDiscovery())->findClassesWithAttribute($this->namespace . '\\Marker');

        self::assertDirectoryDoesNotExist($this->root . '/var', 'a fixture root or a package checked out alone must not grow a cache');
    }

    #[Test]
    public function the_store_refuses_anything_but_scalars(): void
    {
        $store = DiscoveryCacheStore::forProjectRoot($this->root);
        self::assertNotNull($store);

        foreach ([\Semitexa\Core\Discovery\BootSeverity::cases()[0], new \stdClass(), static fn (): int => 1] as $value) {
            try {
                $store->share('routes', ['GET /' => ['access' => $value]]);
                self::fail('stored ' . get_debug_type($value));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('routes.GET /.access', $e->getMessage(), 'the message names the entry to fix');
            }
        }

        self::assertDirectoryDoesNotExist($store->directory(), 'a refused array wrote nothing');
    }

    #[Test]
    public function a_stored_file_is_replaced_by_rename_never_written_in_place(): void
    {
        $store = DiscoveryCacheStore::forProjectRoot($this->root);
        self::assertNotNull($store);

        self::assertTrue($store->writeState('files', ['builtAt' => 1]));
        $file = $store->directory() . '/files.json';
        $inode = fileinode($file);
        self::assertTrue($store->writeState('files', ['builtAt' => 2]));
        clearstatcache();

        self::assertNotSame($inode, fileinode($file), 'written in place: a reader could see half of it');
        self::assertSame(['builtAt' => 2], $store->readState('files'));
        self::assertSame(['files.json'], array_map(basename(...), glob($store->directory() . '/*') ?: []), 'the temp file is gone');
    }

    #[Test]
    public function a_file_that_is_not_what_its_name_says_is_not_read(): void
    {
        $store = DiscoveryCacheStore::forProjectRoot($this->root);
        self::assertNotNull($store);
        $data = ['a' => ['b', 'c']];
        $file = $store->sharedFile('classmap', DiscoveryCacheStore::hashOf($data));

        self::assertSame($data, $store->share('classmap', $data));
        self::assertSame($data, require $file);

        file_put_contents($file, "<?php\nreturn ['a' => ['planted']];\n");
        self::assertSame($data, $store->share('classmap', $data), 'the caller got what a planted file returned');
        self::assertFileDoesNotExist($file, 'a file that does not hold what its name says is removed');

        self::assertSame($data, $store->share('classmap', $data));
        self::assertSame($data, require $file, 'and the next call writes it again');
    }

    #[Test]
    public function a_store_that_cannot_write_leaves_discovery_working(): void
    {
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        // A regular file where the cache directory would go: no user can write under it, root
        // included, which a read-only directory would not stop.
        file_put_contents($this->root . '/var/cache', '');

        self::assertArrayHasKey($this->namespace . '\\Carrier', $this->walk());
        self::assertSame(
            [$this->namespace . '\\Carrier'],
            (new ClassDiscovery())->findClassesWithAttribute($this->namespace . '\\Marker'),
        );
        self::assertFileExists($this->root . '/var/cache', 'the file in the way is left as it was');
    }

    #[Test]
    public function cache_clear_removes_what_discovery_stored(): void
    {
        $this->source('Alpha', "final class Alpha\n{\n}\n");
        $this->walk();
        $directory = $this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY;
        self::assertCount(1, $this->storedClassMaps());

        $tester = new CommandTester(new CacheClearCommand());
        self::assertSame(0, $tester->execute(['--no-reload' => true]), $tester->getDisplay());

        $left = scandir($directory);
        self::assertIsArray($left);
        self::assertSame(['.', '..'], $left, $tester->getDisplay());

        $this->walk();
        self::assertSame(0, $tester->execute(['--no-reload' => true, '--twig' => true]), $tester->getDisplay());
        self::assertCount(1, $this->storedClassMaps(), '--twig clears the templates only');
    }

    /** Review semitexa-core#257: an unsearchable var/cache read as "nothing to clear", and the fallback never ran. */
    #[Test]
    public function cache_clear_reports_a_cache_it_cannot_search_instead_of_nothing_to_clear(): void
    {
        $this->source('Alpha', "final class Alpha\n{\n}\n");
        $this->walk();
        self::assertCount(1, $this->storedClassMaps(), 'the walk stored a classmap to clear');
        // The suite runs as root, which searches any directory: the permission is the seam.
        $command = new class extends CacheClearCommand {
            protected function canSearch(string $directory): bool
            {
                return false;
            }
        };

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--no-reload' => true]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(1, $exit, $display);
        self::assertStringContainsString('Could not clear: var/cache/twig; var/cache/discovery; var/cache/resource-metadata.php', $display);
        self::assertStringNotContainsString('Nothing to clear', $display);
        self::assertCount(1, $this->storedClassMaps(), 'nothing was removed behind the refusal');
    }

    #[Test]
    public function cache_clear_removes_a_link_and_leaves_what_it_points_at(): void
    {
        $this->source('Alpha', "final class Alpha\n{\n}\n");
        $this->walk();
        $directory = $this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY;
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere . '/nested', 0o755, true);
        file_put_contents($elsewhere . '/keep.txt', 'not a cache');
        file_put_contents($elsewhere . '/nested/keep.txt', 'not a cache');
        self::assertTrue(symlink($elsewhere, $directory . '/linked'));
        $contexts = glob($directory . '/*', \GLOB_ONLYDIR);
        self::assertIsArray($contexts);
        $real = array_values(array_filter($contexts, static fn (string $path): bool => !is_link($path)));
        self::assertCount(1, $real);
        self::assertTrue(symlink($elsewhere . '/nested', $real[0] . '/deeper'));

        $tester = new CommandTester(new CacheClearCommand());
        self::assertSame(0, $tester->execute(['--no-reload' => true]), $tester->getDisplay());

        self::assertSame('not a cache', file_get_contents($elsewhere . '/keep.txt'), 'cache:clear deleted through a link');
        self::assertSame('not a cache', file_get_contents($elsewhere . '/nested/keep.txt'), 'cache:clear deleted through a link inside a cache directory');
        $left = scandir($directory);
        self::assertIsArray($left);
        self::assertSame(['.', '..'], $left, 'the links and the real cache directory are gone');
    }

    #[Test]
    public function cache_clear_removes_a_cache_directory_that_is_a_link_without_emptying_its_target(): void
    {
        $elsewhere = $this->root . '/elsewhere';
        mkdir($elsewhere);
        file_put_contents($elsewhere . '/keep.txt', 'not a cache');
        mkdir($this->root . '/var/cache');
        $directory = $this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY;
        self::assertTrue(symlink($elsewhere, $directory));

        $tester = new CommandTester(new CacheClearCommand());
        self::assertSame(0, $tester->execute(['--no-reload' => true]), $tester->getDisplay());
        clearstatcache();

        self::assertSame('not a cache', file_get_contents($elsewhere . '/keep.txt'), 'cache:clear emptied the directory the link points at');
        self::assertFalse(is_link($directory), 'the link is what was in var/cache, so the link is what goes');
        self::assertFileDoesNotExist($directory);
    }

    /**
     * A walk as a new process would make it: nothing remembered in memory.
     *
     * @return array<class-string, string>
     */
    private function walk(): array
    {
        ClassDiscovery::resetSharedCache();

        return (new ClassDiscovery())->getClassMap();
    }

    /**
     * @return list<string>
     */
    private function storedClassMaps(): array
    {
        $files = glob($this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY . '/*/classmap-*.php');
        self::assertIsArray($files, 'the cache directory could not be listed');

        return $files;
    }

    /**
     * A source file old enough for a walk to trust what it remembers of it.
     */
    private function source(string $class, string $declaration): string
    {
        $file = $this->root . '/src/Fixture/' . $class . '.php';
        file_put_contents($file, $this->php($declaration));
        touch($file, time() - 100);
        clearstatcache();

        return $file;
    }

    /**
     * Waits for the next second: mtime and ctime are in seconds, and discovery takes nothing
     * written in the second it ran for settled.
     */
    private function aSecondLater(): void
    {
        time_sleep_until(floor(microtime(true)) + 1.05);
    }

    private function php(string $declaration): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$this->namespace};\n\n{$declaration}";
    }
}
