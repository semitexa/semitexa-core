<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\DiscoveryInterruptedException;
use Semitexa\Core\Lifecycle\WorkerDrainSignal;
use Semitexa\Core\Support\ProjectRoot;

/**
 * What a discovery scan leaves behind when its worker is draining.
 *
 * `Coroutine::cancel()` cannot interrupt a coroutine parked in hooked file I/O, so the
 * scan has to read cancellation itself. The real probe needs Swoole; the consequences do
 * not, and the consequences are the dangerous part — a half-scanned classmap memoised as
 * the process-wide answer is silent, permanent, and survives the request that made it.
 * So the abort is driven through the {@see ClassDiscovery::shouldAbortScan()} seam here
 * and these tests run everywhere.
 */
final class DiscoveryInterruptTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        ProjectRoot::reset();
        ClassDiscovery::resetSharedCache();
        WorkerDrainSignal::reset();
        if ($this->root !== '') {
            $this->removeDirectory($this->root);
            $this->root = '';
        }
    }

    #[Test]
    public function a_draining_worker_stops_the_psr4_scan_instead_of_reading_on(): void
    {
        $this->useFixtureRoot(6);
        $discovery = new DrainingClassDiscovery(abortAfter: 2);

        try {
            $discovery->getClassMap();
            self::fail('an aborted scan must not return quietly');
        } catch (DiscoveryInterruptedException $e) {
            self::assertGreaterThan(0, $e->scanned(), 'the diagnostic says how far it got');
            self::assertStringContainsString('draining', $e->getMessage());
        }

        self::assertSame(3, $discovery->probes, 'the scan stopped at the abort, it did not run to the end');
    }

    #[Test]
    public function an_aborted_scan_memoises_nothing_process_wide(): void
    {
        // The invariant that matters. runInitialization() publishes to the shared cache on
        // its last line, so an abort anywhere before it must leave that cache untouched —
        // otherwise every later coroutine in this worker inherits a partial classmap and
        // silently cannot see the classes that were never reached.
        $this->useFixtureRoot(6);

        try {
            (new DrainingClassDiscovery(abortAfter: 2))->getClassMap();
        } catch (DiscoveryInterruptedException) {
            // expected
        }

        $shared = new \ReflectionProperty(ClassDiscovery::class, 'sharedClassMaps');
        $shared->setAccessible(true);

        self::assertSame([], $shared->getValue(), 'a partial classmap must never be published');
    }

    #[Test]
    public function the_next_scan_still_gets_a_complete_answer(): void
    {
        // The abort must release the production gate, not wedge it: a worker draining one
        // coroutine cannot be allowed to poison discovery for the rest of the process.
        $this->useFixtureRoot(6);

        try {
            (new DrainingClassDiscovery(abortAfter: 2))->getClassMap();
        } catch (DiscoveryInterruptedException) {
            // expected
        }

        $classMap = (new ClassDiscovery())->getClassMap();

        for ($i = 0; $i < 6; ++$i) {
            self::assertArrayHasKey('Semitexa\\Fixture\\Klass' . $i, $classMap);
        }
    }

    #[Test]
    public function a_scan_that_is_never_asked_to_stop_behaves_exactly_as_before(): void
    {
        $this->useFixtureRoot(6);
        $discovery = new DrainingClassDiscovery(abortAfter: \PHP_INT_MAX);

        $classMap = $discovery->getClassMap();

        self::assertCount(6, $classMap);
        self::assertSame(6, $discovery->probes, 'one probe per file read, and no more');
    }

    #[Test]
    public function the_real_drain_signal_stops_a_plain_scan(): void
    {
        // Not the seam — the production path, end to end. This is provable without Swoole
        // precisely because the signal had to move off Swoole's cancellation flag, which
        // a refused cancel() never sets.
        $this->useFixtureRoot(6);
        WorkerDrainSignal::begin();

        $this->expectException(DiscoveryInterruptedException::class);

        (new ClassDiscovery())->getClassMap();
    }

    #[Test]
    public function a_worker_that_is_not_draining_scans_normally(): void
    {
        $this->useFixtureRoot(6);

        self::assertFalse(WorkerDrainSignal::isDraining());
        self::assertCount(6, (new ClassDiscovery())->getClassMap());
    }

    private function useFixtureRoot(int $classes): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-discovery-interrupt-' . bin2hex(random_bytes(6));
        $src = $this->root . '/src/Fixture';
        $composerDir = $this->root . '/vendor/composer';
        mkdir($src, 0o755, true);
        mkdir($composerDir, 0o755, true);

        file_put_contents($this->root . '/composer.json', "{}\n");
        file_put_contents($composerDir . '/autoload_classmap.php', "<?php\nreturn [];\n");
        file_put_contents(
            $composerDir . '/autoload_psr4.php',
            "<?php\nreturn [\n    'Semitexa\\\\Fixture\\\\' => [__DIR__ . '/../../src/Fixture'],\n];\n",
        );

        for ($i = 0; $i < $classes; ++$i) {
            file_put_contents(
                $src . '/Klass' . $i . '.php',
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace Semitexa\\Fixture;\n\nfinal class Klass{$i}\n{\n}\n",
            );
        }

        ProjectRoot::reset();
        ClassDiscovery::resetSharedCache();
        $property = new \ReflectionProperty(ProjectRoot::class, 'root');
        $property->setAccessible(true);
        $property->setValue(null, $this->root);
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}

/**
 * Stands in for a cancelled coroutine without needing one: the seam answers "yes, stand
 * down" on the nth probe, which is what Swoole's flag would do at an arbitrary file.
 */
final class DrainingClassDiscovery extends ClassDiscovery
{
    public int $probes = 0;

    public function __construct(private readonly int $abortAfter) {}

    protected function shouldAbortScan(): bool
    {
        ++$this->probes;

        return $this->probes > $this->abortAfter;
    }
}
