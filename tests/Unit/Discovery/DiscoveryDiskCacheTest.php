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
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What discovery found is kept in var/cache/discovery and read by the next process instead of
 * being worked out again (tk-discovery-registries-as-php-arrays): the walk reads only the files
 * that changed, and the attribute scan loads no class while the sources are the ones it read.
 *
 * Each test is a project of its own under the temp directory, with a namespace of its own, so
 * a class one test loaded is never the class another one expects to be absent.
 */
final class DiscoveryDiskCacheTest extends TestCase
{
    private string $root = '';

    private string $namespace = '';

    private ?BootDiagnostics $diagnostics = null;

    protected function setUp(): void
    {
        $current = new ReflectionProperty(BootDiagnostics::class, 'current');
        $held = $current->getValue();
        $this->diagnostics = $held instanceof BootDiagnostics ? $held : null;
        $current->setValue(null, null);

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
        ProjectRoot::reset();
        ClassDiscovery::resetSharedCache();
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
        self::assertIsArray($table['files'][$keys[0]]);
        $table['files'][$keys[0]][3] = $this->namespace . '\\FromTheTable';
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
    public function a_second_process_answers_an_attribute_lookup_without_loading_a_class(): void
    {
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $this->source('Plain', "final class Plain\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        $this->aSecondLater();

        $first = $this->inAFreshProcess($marker);
        self::assertSame([$this->namespace . '\\Carrier'], $first['found']);
        self::assertContains($this->namespace . '\\Plain', $first['loaded'], 'the first scan loads every class: that is how it reads them');

        $second = $this->inAFreshProcess($marker);
        self::assertSame($first['found'], $second['found']);
        self::assertSame([], $second['loaded'], 'the second process scanned the classmap again instead of reading what the first one stored');
    }

    #[Test]
    public function a_new_carrier_appears_in_the_next_process_with_nothing_cleared(): void
    {
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        $this->inAFreshProcess($marker);
        $this->inAFreshProcess($marker);

        $this->source('Late', "#[Marker]\nfinal class Late\n{\n}\n");
        $found = $this->inAFreshProcess($marker)['found'];
        sort($found);

        self::assertSame([$this->namespace . '\\Carrier', $this->namespace . '\\Late'], $found, 'a handler added to the sources must be found by the next boot');
    }

    #[Test]
    public function an_edited_carrier_is_scanned_again(): void
    {
        $file = $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        self::assertSame([$this->namespace . '\\Carrier'], $this->inAFreshProcess($marker)['found']);

        // Same class, same size, attribute gone: only the mtime says the file changed.
        file_put_contents($file, str_replace('#[Marker]', '/*plain*/', (string) file_get_contents($file)));
        touch($file, time() - 50);
        clearstatcache();

        self::assertSame([], $this->inAFreshProcess($marker)['found'], 'the stored scan answered for a file edited since');
    }

    #[Test]
    public function a_carrier_edited_in_the_second_of_the_scan_is_scanned_again(): void
    {
        $file = $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $mtime = time() + 5;
        touch($file, $mtime);
        clearstatcache();
        $marker = $this->namespace . '\\Marker';
        self::assertSame([$this->namespace . '\\Carrier'], $this->inAFreshProcess($marker)['found']);

        file_put_contents($file, str_replace('#[Marker]', '/*plain*/', (string) file_get_contents($file)));
        touch($file, $mtime);
        clearstatcache();

        self::assertSame([], $this->inAFreshProcess($marker)['found']);
    }

    #[Test]
    public function a_carrier_rewritten_under_the_same_mtime_and_size_is_scanned_again(): void
    {
        $file = $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $mtime = (int) filemtime($file);
        $size = (int) filesize($file);
        $marker = $this->namespace . '\\Marker';
        $this->aSecondLater();
        self::assertSame([$this->namespace . '\\Carrier'], $this->inAFreshProcess($marker)['found']);

        file_put_contents($file, str_replace('#[Marker]', '/*plain*/', (string) file_get_contents($file)));
        touch($file, $mtime);
        clearstatcache();
        self::assertSame([$mtime, $size], [filemtime($file), filesize($file)]);

        self::assertSame([], $this->inAFreshProcess($marker)['found'], 'same mtime, same size: the stored scan answered for a file written since');
    }

    #[Test]
    public function a_scan_of_a_class_older_than_its_file_is_not_stored(): void
    {
        // A worker after server:reload: forked from a master that declared the class when the
        // server started, scanning after the file gained an attribute. Reflection shows it the
        // old class. Stored, that answer would sit under the file's new mtime, and a restart,
        // which used to cure it, would read it back.
        $file = $this->source('Carrier', "final class Carrier\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        $prelude = $this->root . '/declare-then-edit.php';
        file_put_contents($prelude, '<?php require ' . var_export($file, true) . ';'
            . ' file_put_contents(' . var_export($file, true) . ', ' . var_export($this->php("#[Marker]\nfinal class Carrier\n{\n}\n"), true) . ');'
            . ' clearstatcache();'
            // Past the second of the edit, so nothing but the age of the class can refuse the scan.
            . ' time_sleep_until(floor(microtime(true)) + 1.2);');

        $stale = $this->inAFreshProcess($marker, $prelude);
        self::assertSame([], $stale['found'], 'that process runs the old class, and says so');

        self::assertSame(
            [$this->namespace . '\\Carrier'],
            $this->inAFreshProcess($marker)['found'],
            'the next process read what a process holding the old class had stored',
        );
    }

    #[Test]
    public function the_stored_scan_is_scalars_only_and_names_the_classes_it_could_not_read(): void
    {
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $this->source('Broken', "final class Broken extends NoSuchParent\n{\n}\n");
        $marker = $this->namespace . '\\Marker';

        $first = $this->inAFreshProcess($marker);
        $second = $this->inAFreshProcess($marker);

        self::assertSame([$this->namespace . '\\Broken'], array_keys($first['unloadable']));
        self::assertSame($first['unloadable'], $second['unloadable'], 'a boot that reads the stored scan must still say which classes it left out');
        self::assertSame(1, $second['warnings'], 'and report them as the scan did');

        $files = glob($this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY . '/*/attributes-*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $stored = require $files[0];
        self::assertIsArray($stored);
        self::assertIsArray($stored['attributes'] ?? null);
        self::assertSame([$this->namespace . '\\Carrier'], $stored['attributes'][$marker] ?? null);
        array_walk_recursive($stored, static function (mixed $value): void {
            self::assertIsString($value, 'an object or enum case in the file puts the whole array on every worker\'s heap');
        });
    }

    #[Test]
    public function the_scan_runs_once_and_every_lookup_reads_it(): void
    {
        // Loaded here: whether a parent in a temp root autoloads depends on which Composer
        // loader an earlier test left first, and this test is about the lookup, not that.
        require_once $this->source('BaseMarker', "#[\\Attribute]\nclass BaseMarker\n{\n}\n");
        $this->source('ChildMarker', "#[\\Attribute]\nfinal class ChildMarker extends BaseMarker\n{\n}\n");
        $this->source('A', "#[ChildMarker]\nfinal class A\n{\n}\n");
        $this->source('B', "#[BaseMarker]\n#[Marker]\nfinal class B\n{\n}\n");
        $this->source('C', "#[marker]\nfinal class C\n{\n}\n");

        $discovery = new class extends ClassDiscovery {
            public int $probes = 0;

            protected function shouldAbortScan(): bool
            {
                ++$this->probes;

                return false;
            }
        };
        $classes = count($discovery->getClassMap());
        $afterWalk = $discovery->probes;

        self::assertSame(
            [$this->namespace . '\\A', $this->namespace . '\\B'],
            $this->sorted($discovery->findClassesWithAttributeInstanceof($this->namespace . '\\BaseMarker')),
            'a carrier of the attribute or of a subclass of it, each once',
        );
        self::assertSame(
            [$this->namespace . '\\B', $this->namespace . '\\C'],
            $this->sorted($discovery->findClassesWithAttribute($this->namespace . '\\Marker')),
            'PHP matches an attribute name whatever its case',
        );
        self::assertSame([$this->namespace . '\\A'], $discovery->findClassesWithAttribute($this->namespace . '\\ChildMarker'));
        self::assertSame([], $discovery->findClassesWithAttribute($this->namespace . '\\Nobody'));

        self::assertSame($classes, $discovery->probes - $afterWalk, 'four lookups, one pass over the classmap');
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
    public function a_classmap_seeded_by_hand_is_never_stored(): void
    {
        (new ReflectionProperty(ClassDiscovery::class, 'sharedClassMaps'))->setValue(null, [
            $this->root => [$this->namespace . '\\Seeded' => $this->root . '/src/Fixture/Seeded.php'],
        ]);

        (new ClassDiscovery())->findClassesWithAttribute($this->namespace . '\\Marker');

        self::assertDirectoryDoesNotExist(
            $this->root . '/' . DiscoveryCacheStore::RELATIVE_DIRECTORY,
            'a map no walk produced describes no sources a later process could check it against',
        );
    }

    #[Test]
    public function a_lookup_with_another_classmap_is_not_answered_from_the_first_ones_scan(): void
    {
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        self::assertSame([$this->namespace . '\\Carrier'], (new ClassDiscovery())->findClassesWithAttribute($marker));

        // What tests/Support/FixtureModules does: change the shared map under a live process.
        $shared = new ReflectionProperty(ClassDiscovery::class, 'sharedClassMaps');
        $shared->setValue(null, [$this->root => []]);
        (new ReflectionProperty(ClassDiscovery::class, 'sharedAttributeCaches'))->setValue(null, []);

        self::assertSame([], (new ClassDiscovery())->findClassesWithAttribute($marker));
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

        self::assertSame([], glob($store->directory() . '/*') ?: [], 'a refused array wrote nothing');
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
        $hash = DiscoveryCacheStore::hashOf($data);

        self::assertSame($data, $store->share('attributes', $data));
        self::assertSame($data, $store->load('attributes', $hash));

        file_put_contents($store->sharedFile('attributes', $hash), "<?php\nreturn ['a' => ['planted']];\n");
        self::assertNull($store->load('attributes', $hash));
        self::assertSame($data, $store->share('attributes', $data), 'the caller still gets its own array');
        self::assertNull($store->load('attributes', '../../../etc/passwd'));
        self::assertNull($store->load('attributes', str_repeat('0', 32)), 'a pointer to a file that is gone is a miss');
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
        self::assertNotSame([], glob($directory . '/*/classmap-*.php'));

        $tester = new CommandTester(new CacheClearCommand());
        $tester->execute(['--no-reload' => true]);

        self::assertSame([], glob($directory . '/*') ?: [], $tester->getDisplay());

        $tester->execute(['--no-reload' => true, '--twig' => true]);
        $this->walk();
        $tester->execute(['--no-reload' => true, '--twig' => true]);
        self::assertNotSame([], glob($directory . '/*/classmap-*.php'), '--twig clears the templates only');
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
     * @return array{found: list<string>, loaded: list<string>, unloadable: array<string, string>, warnings: int}
     */
    private function inAFreshProcess(string $attribute, string $prelude = ''): array
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);
        $script = $this->root . '/lookup.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $autoload, $root, $attribute, $namespace, $prelude] = $argv;
            require $autoload;
            if ($prelude !== '') {
                require $prelude;
            }
            (new ReflectionProperty(Semitexa\Core\Support\ProjectRoot::class, 'root'))->setValue(null, $root);
            $discovery = new Semitexa\Core\Discovery\ClassDiscovery();
            $found = $discovery->findClassesWithAttribute($attribute);
            echo json_encode([
                'found' => $found,
                'loaded' => array_values(array_filter(get_declared_classes(), static fn (string $c): bool => str_starts_with($c, $namespace))),
                'unloadable' => $discovery->unloadableClasses(),
                'warnings' => count(Semitexa\Core\Discovery\BootDiagnostics::current()->getWarnings()),
            ]);
            PHP);

        $command = implode(' ', array_map(escapeshellarg(...), [
            \PHP_BINARY, $script, dirname($loader, 2) . '/autoload.php', $this->root, $attribute, $this->namespace . '\\', $prelude,
        ]));
        exec($command . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        clearstatcache();

        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));

        /** @var array{found: list<string>, loaded: list<string>, unloadable: array<string, string>, warnings: int} $result */
        return $result;
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

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    private function sorted(array $classes): array
    {
        sort($classes);

        return $classes;
    }
}
