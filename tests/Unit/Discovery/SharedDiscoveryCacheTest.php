<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;
use Swoole\Coroutine;

/**
 * Discovery is a per-process fact, not a per-object one.
 *
 * A worker's filesystem does not change under it, so the classmap it walks at boot is the
 * answer for the worker's whole life. But `new ClassDiscovery()` is the ordinary way to
 * reach discovery without a container — `new OrmManager()` alone does it from 41 call
 * sites — and each instance used to redo the entire walk: every source directory opened,
 * every PHP file read, every class in the map autoloaded and reflected.
 *
 * Harmless once at boot. On a five-second timer it pinned a core indefinitely: a task tick
 * reached settings through a fresh OrmManager, rebuilding the mapper registry and therefore
 * rescanning everything, every tick, reading 12 MB/s forever — and at the default 128M
 * memory limit the worker died mid-scan and was respawned straight back into it.
 *
 * The sentinel here is deliberate. Asserting "the second instance was FASTER" would pass on
 * a warm page cache even with the sharing removed; asserting it returned data that exists
 * nowhere on disk can only be true if it never went to disk.
 */
final class SharedDiscoveryCacheTest extends TestCase
{
    private const SENTINEL = ['Sentinel\\NotOnDisk' => '/nowhere/NotOnDisk.php'];

    protected function setUp(): void
    {
        ClassDiscovery::resetSharedCache();
    }

    protected function tearDown(): void
    {
        ClassDiscovery::resetSharedCache();
    }

    #[Test]
    public function a_second_instance_reuses_the_first_walk_instead_of_repeating_it(): void
    {
        self::seedSharedClassMap(self::SENTINEL);

        $discovery = new ClassDiscovery();
        $discovery->initialize();

        self::assertSame(
            self::SENTINEL,
            self::classMapOf($discovery),
            'a fresh instance walked the filesystem again instead of reusing what this process '
                . 'had already discovered — the timer-driven rescan is back',
        );
    }

    #[Test]
    public function an_explicit_reset_throws_the_shared_answers_away(): void
    {
        self::seedSharedClassMap(self::SENTINEL);

        ClassDiscovery::resetSharedCache();

        $discovery = new ClassDiscovery();
        $discovery->initialize();

        $classMap = self::classMapOf($discovery);
        self::assertNotSame(self::SENTINEL, $classMap, 'the explicit reset did not clear the cache');
        self::assertNotEmpty($classMap, 'the real walk should have found this project');
    }

    #[Test]
    public function resetting_the_project_root_does_not_clear_the_cache(): void
    {
        // Not a detail — a load-bearing negative. OrmManager::createPool() calls
        // ProjectRoot::reset() under Swoole once per manager instance, purely to re-read DB_*
        // from the env files. Wiring cache invalidation into that call wipes discovery on the
        // exact hot path this cache exists to protect, which is how the first attempt at this
        // fix left a worker still reading 11 MB/s. Keying by root already covers the case that
        // reset() is really about.
        self::seedSharedClassMap(self::SENTINEL);

        ProjectRoot::reset();

        $discovery = new ClassDiscovery();
        $discovery->initialize();

        self::assertSame(
            self::SENTINEL,
            self::classMapOf($discovery),
            'ProjectRoot::reset() must not invalidate discovery — it is called constantly',
        );
    }

    #[Test]
    public function two_real_instances_agree_on_what_they_found(): void
    {
        $first = new ClassDiscovery();
        $first->initialize();

        $second = new ClassDiscovery();
        $second->initialize();

        self::assertSame(self::classMapOf($first), self::classMapOf($second));
        self::assertNotEmpty(self::classMapOf($first));
    }

    #[Test]
    public function an_attribute_lookup_is_answered_once_per_process(): void
    {
        $first = new ClassDiscovery();
        $found = $first->findClassesWithAttribute(\Semitexa\Core\Attribute\AsService::class);
        self::assertNotEmpty($found, 'the project should declare at least one service');

        $shared = self::sharedAttributeCaches();
        self::assertNotEmpty($shared, 'the attribute answer was not shared with the rest of the process');

        // The expensive half is autoload+reflect over the whole classmap, so a second
        // instance must take the answer as-is rather than recomputing it.
        $second = new ClassDiscovery();
        self::assertSame(
            $found,
            $second->findClassesWithAttribute(\Semitexa\Core\Attribute\AsService::class),
        );
    }


    #[Test]
    public function two_instances_racing_in_two_coroutines_produce_once(): void
    {
        // Reviewer's case on semitexa-core#111: sharing the RESULT is not enough while the gate
        // that elects a producer is per-object. Two coroutines holding two fresh instances would
        // each see an empty shared cache, each find its own gate free, and each run the scan.
        //
        // Driving runOncePerKey directly with a counting producer tests that election on its own,
        // without depending on how long a real scan happens to take. The producer must contain a
        // suspension point, or the first coroutine finishes before the second starts and there is
        // no race to observe.
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }

        $produced = 0;
        $done = false;
        $first = new ClassDiscovery();
        $second = new ClassDiscovery();

        Coroutine\run(static function () use ($first, $second, &$produced, &$done): void {
            // By REFERENCE, deliberately: an arrow function would capture $done by value, the
            // predicate would never turn true, and the retry loop would spin on a closed gate
            // forever. That is a bug in the test, not the gate — but it is an easy one to write.
            $isDone = static function () use (&$done): bool {
                return $done;
            };
            $produce = static function () use (&$produced, &$done): void {
                $produced++;
                Coroutine::sleep(0.01);
                $done = true;
            };

            foreach ([$first, $second] as $discovery) {
                Coroutine::create(static function () use ($discovery, $isDone, $produce): void {
                    $method = new ReflectionMethod(ClassDiscovery::class, 'runOncePerKey');
                    $method->setAccessible(true);
                    $method->invoke($discovery, '@race-probe', $isDone, $produce);
                });
            }
        });

        self::assertSame(
            1,
            $produced,
            'both instances ran the producer — the gate elects per object again, so a concurrent '
                . 'boot would scan the whole tree once per instance',
        );
    }

    #[Test]
    public function a_waiter_woken_by_another_instance_adopts_its_answer(): void
    {
        // The other half of the same fix: the predicate and the post-gate hydration have to look
        // at the SHARED cache. A waiter that only consults its own empty state concludes nothing
        // was produced and scans anyway, which puts the duplicate work straight back.
        self::seedSharedClassMap(self::SENTINEL);

        $discovery = new ClassDiscovery();
        $discovery->initialize();

        self::assertSame(self::SENTINEL, self::classMapOf($discovery));
        self::assertTrue(self::isInitialized($discovery), 'the instance must consider itself ready');
    }

    private static function isInitialized(ClassDiscovery $discovery): bool
    {
        $property = new ReflectionProperty(ClassDiscovery::class, 'initialized');
        $property->setAccessible(true);

        return (bool) $property->getValue($discovery);
    }

    /** @param array<string, string> $classMap */
    private static function seedSharedClassMap(array $classMap): void
    {
        $property = new ReflectionProperty(ClassDiscovery::class, 'sharedClassMaps');
        $property->setAccessible(true);
        $property->setValue(null, [ProjectRoot::get() => $classMap]);
    }

    /** @return array<string, string> */
    private static function classMapOf(ClassDiscovery $discovery): array
    {
        $property = new ReflectionProperty(ClassDiscovery::class, 'classMap');
        $property->setAccessible(true);

        /** @var array<string, string> $value */
        $value = $property->getValue($discovery);

        return $value;
    }

    /** @return array<string, mixed> */
    private static function sharedAttributeCaches(): array
    {
        $property = new ReflectionProperty(ClassDiscovery::class, 'sharedAttributeCaches');
        $property->setAccessible(true);

        /** @var array<string, mixed> $value */
        $value = $property->getValue(null);

        return $value;
    }
}
