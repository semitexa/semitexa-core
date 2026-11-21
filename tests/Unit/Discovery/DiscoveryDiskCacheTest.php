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

    private string $marker = '';

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
        $this->marker = $this->source('Marker', "#[\\Attribute]\nfinal class Marker\n{\n}\n");

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
        // Past the second the fixture was written in, or the second process would refuse the
        // stored scan, scan again, and report the class from that scan instead of the stored one.
        $this->aSecondLater();

        $first = $this->inAFreshProcess($marker);
        $second = $this->inAFreshProcess($marker);

        self::assertContains($this->namespace . '\\Carrier', $first['loaded']);
        self::assertSame([], $second['loaded'], 'the second process scanned again: what follows would not be about the stored scan');
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
    public function an_instanceof_lookup_matches_an_alias_of_the_attribute_class(): void
    {
        require_once $this->marker;
        class_alias($this->namespace . '\\Marker', $this->namespace . '\\MarkerAlias');
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");

        self::assertSame(
            [$this->namespace . '\\Carrier'],
            (new ClassDiscovery())->findClassesWithAttributeInstanceof($this->namespace . '\\MarkerAlias'),
            'ReflectionAttribute::IS_INSTANCEOF resolves both names: an alias is the class',
        );
        self::assertSame([], (new ClassDiscovery())->findClassesWithAttribute($this->namespace . '\\MarkerAlias'), 'a lookup by name stays a lookup by name');
    }

    #[Test]
    public function a_class_that_could_not_load_is_tried_again_by_a_process_that_reads_the_stored_scan(): void
    {
        // The parent is outside the classmap, so nothing discovery fingerprints changes when
        // it turns up. Only trying the class again can find that it loads now.
        $outside = 'Outside' . substr($this->namespace, -10);
        $this->source('Carrier', "#[Marker]\nfinal class Carrier extends \\{$outside}\\Base\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        $this->aSecondLater();

        $first = $this->inAFreshProcess($marker);
        self::assertSame([], $first['found']);
        self::assertSame([$this->namespace . '\\Carrier'], array_keys($first['unloadable']));
        $again = $this->inAFreshProcess($marker);
        self::assertSame([], $again['loaded'], 'the scan is stored, and read: the next process starts from it');

        $prelude = $this->root . '/declare-the-parent.php';
        file_put_contents($prelude, "<?php\nnamespace {$outside};\nclass Base\n{\n}\n");
        $restored = $this->inAFreshProcess($marker, $prelude);

        self::assertSame([$this->namespace . '\\Carrier'], $restored['found'], 'the stored scan went on saying the class does not load');
        self::assertSame([], $restored['unloadable']);
    }

    #[Test]
    public function a_scan_over_bytecode_opcache_never_revalidates_is_not_stored(): void
    {
        // validate_timestamps=0: OPcache keeps what it compiled whatever happens to the file,
        // so a class the scan autoloads may be the old one. Nothing declared it beforehand.
        $file = $this->source('Carrier', "final class Carrier\n{\n}\n");
        $marker = $this->namespace . '\\Marker';
        $prelude = $this->root . '/compile-then-edit.php';
        // Compiled by a child that shares this process's OPcache and exits: the bytecode stays,
        // the class is declared nowhere. That is a worker beside the one that loaded the file.
        file_put_contents($prelude, '<?php $child = new Swoole\Process(static function (): void { require ' . var_export($file, true) . '; });'
            . ' $child->start(); Swoole\Process::wait();'
            . ' if (!opcache_is_script_cached(' . var_export($file, true) . ') || class_exists(' . var_export($this->namespace . '\\Carrier', true) . ', false)) { throw new RuntimeException("the file must be in OPcache and its class not declared"); }'
            . ' file_put_contents(' . var_export($file, true) . ', ' . var_export($this->php("#[Marker]\nfinal class Carrier\n{\n}\n"), true) . ');'
            . ' clearstatcache();'
            . ' time_sleep_until(floor(microtime(true)) + 1.2);');

        $stale = $this->inAFreshProcess($marker, $prelude, [
            'opcache.enable=1', 'opcache.enable_cli=1', 'opcache.validate_timestamps=0', 'opcache.file_update_protection=0',
        ]);
        self::assertSame([], $stale['found'], 'the scenario did not happen: the class was not read from the old bytecode');

        self::assertSame(
            [$this->namespace . '\\Carrier'],
            $this->inAFreshProcess($marker)['found'],
            'the next process read a scan made over stale bytecode',
        );
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
    public function a_lookup_waiting_on_another_classmaps_scan_makes_its_own_when_that_one_ends(): void
    {
        $this->source('Carrier', "#[Marker]\nfinal class Carrier\n{\n}\n");

        $result = $this->inACoroutineProcess(<<<'PHP'
            $scanning = new class extends Semitexa\Core\Discovery\ClassDiscovery {
                public bool $slow = false;

                protected function shouldAbortScan(): bool
                {
                    if ($this->slow) {
                        Swoole\Coroutine::sleep(0.05);
                    }

                    return false;
                }
            };
            $scanning->getClassMap();
            $scanning->slow = true;
            Swoole\Coroutine::create(static function () use ($scanning, $marker, &$out): void {
                $out['scanning'] = $scanning->findClassesWithAttribute($marker);
            });
            // The first lookup is asleep inside its scan. A second one, on another classmap of
            // the same root, now waits on that scan's gate.
            (new ReflectionProperty(Semitexa\Core\Discovery\ClassDiscovery::class, 'sharedClassMaps'))->setValue(null, [$root => []]);
            $out['waiting'] = (new Semitexa\Core\Discovery\ClassDiscovery())->findClassesWithAttribute($marker);
            PHP);

        self::assertSame([$this->namespace . '\\Carrier'], $result['scanning'] ?? null);
        self::assertSame([], $result['waiting'] ?? null, 'the waiter took the other classmap\'s answer');
    }

    #[Test]
    public function an_answer_computed_across_a_reset_is_not_shared_with_the_classmap_that_followed(): void
    {
        $this->source('BaseMarker', "#[\\Attribute]\nclass BaseMarker\n{\n}\n");
        $this->source('Carrier', "#[BaseMarker]\nfinal class Carrier\n{\n}\n");
        $this->source('Other', "#[Slow]\nfinal class Other\n{\n}\n");

        $result = $this->inACoroutineProcess(<<<'PHP'
            $base = $namespace . 'BaseMarker';
            // An attribute class that does not exist: the instanceof lookup asks the autoloader
            // for it, and under SWOOLE_HOOK_ALL an autoload is where a coroutine is suspended.
            spl_autoload_register(static function (string $class) use ($namespace): void {
                if ($class === $namespace . 'Slow') {
                    Swoole\Coroutine::sleep(0.3);
                }
            });
            $first = new Semitexa\Core\Discovery\ClassDiscovery();
            $first->findClassesWithAttribute($marker);
            Swoole\Coroutine::create(static function () use ($first, $base, &$out): void {
                $out['suspended'] = $first->findClassesWithAttributeInstanceof($base);
            });
            Semitexa\Core\Discovery\ClassDiscovery::resetSharedCache();
            (new ReflectionProperty(Semitexa\Core\Discovery\ClassDiscovery::class, 'sharedClassMaps'))->setValue(null, [$root => []]);
            $out['after_reset'] = (new Semitexa\Core\Discovery\ClassDiscovery())->findClassesWithAttributeInstanceof($base);
            Swoole\Coroutine::sleep(0.6);
            $out['later'] = (new Semitexa\Core\Discovery\ClassDiscovery())->findClassesWithAttributeInstanceof($base);
            PHP);

        self::assertSame([$this->namespace . '\\Carrier'], $result['suspended'] ?? null, 'the suspended lookup still answers for its own classmap');
        self::assertSame([], $result['after_reset'] ?? null);
        self::assertSame([], $result['later'] ?? null, 'the lookup that resumed wrote its old answer over the new classmap\'s');
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
        $answers = (new ReflectionProperty(ClassDiscovery::class, 'sharedAttributeCaches'))->getValue();
        self::assertIsArray($answers);
        self::assertIsArray($answers[$this->root] ?? null);
        self::assertSame([$this->namespace . '\\Carrier'], $answers[$this->root][$marker] ?? null, 'the first answer is still remembered: only discovery can drop it');

        self::assertSame([], (new ClassDiscovery())->findClassesWithAttribute($marker), 'answered from what another classmap had found');
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
     * @param list<string> $ini php.ini settings for that process, as `name=value`
     * @return array{found: list<string>, loaded: list<string>, unloadable: array<string, string>, warnings: int}
     */
    private function inAFreshProcess(string $attribute, string $prelude = '', array $ini = []): array
    {
        $script = $this->root . '/lookup.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $autoload, $root, $namespace, $attribute, $prelude] = $argv;
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

        /** @var array{found: list<string>, loaded: list<string>, unloadable: array<string, string>, warnings: int} $result */
        $result = $this->runScript($script, [$attribute, $prelude], $ini);

        return $result;
    }

    /**
     * Runs $body inside Coroutine\run in a process of its own, with $root, $marker, $namespace
     * and $out in scope, and returns $out. A process of its own because the failure it looks
     * for is a coroutine spinning on a closed channel: that never yields, so nothing in the
     * same process could stop it. Here it is killed after 30 seconds and the test fails.
     *
     * @return array<array-key, mixed>
     */
    private function inACoroutineProcess(string $body): array
    {
        $script = $this->root . '/coroutines.php';
        file_put_contents($script, <<<'PHP'
            <?php
            [, $autoload, $root, $namespace, $marker] = $argv;
            require $autoload;
            (new ReflectionProperty(Semitexa\Core\Support\ProjectRoot::class, 'root'))->setValue(null, $root);
            $out = [];
            Swoole\Coroutine\run(static function () use ($root, $namespace, $marker, &$out): void {
            PHP . "\n" . $body . "\n});\necho json_encode(\$out);\n");

        return $this->runScript($script, [$this->namespace . '\\Marker', ''], []);
    }

    /**
     * @param list<string> $arguments after the autoloader, the root and the namespace
     * @param list<string> $ini
     * @return array<array-key, mixed> what the script printed, decoded
     */
    private function runScript(string $script, array $arguments, array $ini): array
    {
        $loader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        self::assertIsString($loader);

        $command = ['timeout', '30', \PHP_BINARY];
        foreach ($ini as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        array_push($command, $script, dirname($loader, 2) . '/autoload.php', $this->root, $this->namespace . '\\', ...$arguments);

        exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, $exit === 124 ? 'the process never finished: a coroutine is spinning' : implode("\n", $output));
        clearstatcache();

        $result = json_decode(implode("\n", $output), true);
        self::assertIsArray($result, implode("\n", $output));

        return $result;
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
