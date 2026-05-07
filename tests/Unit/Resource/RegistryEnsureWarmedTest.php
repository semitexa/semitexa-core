<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Metadata\CacheLoadResult;
use Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * Phase 3d: lifecycle warming behavior. The Registry must:
 *   - prefer the cache file in production
 *   - rebuild from discovery in dev / on cache miss / on corrupt cache
 *   - rewrite the cache after a production rebuild
 *   - be idempotent across repeated ensureWarmed() calls
 *   - never silently merge incompatible metadata sources
 */
final class RegistryEnsureWarmedTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/semitexa-meta-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cachePath)) {
            @unlink($this->cachePath);
        }
    }

    private function freshRegistry(): ResourceMetadataRegistry
    {
        return ResourceMetadataRegistry::forTesting(new ResourceMetadataExtractor());
    }

    private function discovery(): ClassDiscovery
    {
        return new InMemoryDiscoveryFor3d([
            AddressResource::class,
            ProfileResource::class,
            CustomerResource::class,
        ]);
    }

    #[Test]
    public function production_cache_miss_builds_from_discovery_and_writes_cache(): void
    {
        $registry = $this->freshRegistry();
        $cache    = ResourceMetadataCacheFile::forPath($this->cachePath);

        self::assertFalse($cache->exists());
        $registry->ensureWarmed($this->discovery(), $cache, production: true);

        self::assertTrue($registry->isWarmed());
        self::assertSame(3, count($registry->all()));
        self::assertTrue($cache->exists(), 'Cache file must be written on production rebuild.');
    }

    #[Test]
    public function production_cache_hit_loads_from_cache_without_calling_discovery(): void
    {
        // Seed the cache via a first warmup.
        $seed = $this->freshRegistry();
        $cache = ResourceMetadataCacheFile::forPath($this->cachePath);
        $seed->ensureWarmed($this->discovery(), $cache, production: true);

        // Second registry, second warmup — discovery returns nothing, but the
        // registry must hydrate from the cache file alone.
        $second = $this->freshRegistry();
        $second->ensureWarmed(new InMemoryDiscoveryFor3d([]), $cache, production: true);

        self::assertTrue($second->isWarmed());
        self::assertSame(3, count($second->all()));
        self::assertTrue($second->has(CustomerResource::class));
    }

    #[Test]
    public function production_corrupt_cache_falls_back_to_discovery_and_rewrites(): void
    {
        // Write a deliberately broken cache file.
        file_put_contents($this->cachePath, "<?php\nreturn ['version' => 999, 'metadata' => 'not-an-array'];\n");
        $cache = ResourceMetadataCacheFile::forPath($this->cachePath);

        $registry = $this->freshRegistry();
        $registry->ensureWarmed($this->discovery(), $cache, production: true);

        self::assertSame(3, count($registry->all()), 'Corrupt cache must fall back to discovery.');
        // Cache should be rewritten with a valid payload now.
        self::assertTrue($cache->exists());
        $rehydrate = $this->freshRegistry();
        self::assertSame(
            CacheLoadResult::Hit,
            $cache->loadWithResult($rehydrate, null),
            'Corrupt cache must be replaced with a valid one.',
        );
    }

    #[Test]
    public function development_mode_always_rebuilds_from_discovery(): void
    {
        // Even with a valid cache file present, dev mode must use discovery.
        $seed = $this->freshRegistry();
        $cache = ResourceMetadataCacheFile::forPath($this->cachePath);
        $seed->ensureWarmed($this->discovery(), $cache, production: true);

        $dev = $this->freshRegistry();
        $dev->ensureWarmed(new InMemoryDiscoveryFor3d([]), $cache, production: false);

        self::assertSame(0, count($dev->all()), 'Dev mode must ignore the cache and rebuild from discovery (which is empty here).');
    }

    #[Test]
    public function ensure_warmed_is_idempotent(): void
    {
        $registry = $this->freshRegistry();
        $cache    = ResourceMetadataCacheFile::forPath($this->cachePath);

        $registry->ensureWarmed($this->discovery(), $cache, production: true);
        $hash1 = md5(serialize($registry->all()));

        $registry->ensureWarmed($this->discovery(), $cache, production: true);
        $registry->ensureWarmed($this->discovery(), $cache, production: false);
        $hash2 = md5(serialize($registry->all()));

        self::assertSame($hash1, $hash2, 'Repeated ensureWarmed() calls must not mutate registry state.');
    }

    #[Test]
    public function is_warmed_reflects_actual_state(): void
    {
        $registry = $this->freshRegistry();
        $cache    = ResourceMetadataCacheFile::forPath($this->cachePath);

        self::assertFalse($registry->isWarmed());
        $registry->ensureWarmed($this->discovery(), $cache, production: true);
        self::assertTrue($registry->isWarmed());

        $registry->reset();
        self::assertFalse($registry->isWarmed());
    }

    #[Test]
    public function dev_mode_does_not_write_the_cache(): void
    {
        // Dev rebuilds from discovery but must NOT create a cache file —
        // we don't want stray production-shaped artefacts during local dev.
        $registry = $this->freshRegistry();
        $cache    = ResourceMetadataCacheFile::forPath($this->cachePath);

        self::assertFalse($cache->exists());
        $registry->ensureWarmed($this->discovery(), $cache, production: false);
        self::assertFalse($cache->exists(), 'Dev mode must not write the cache file.');
    }

    #[Test]
    public function cache_write_failure_does_not_crash_boot(): void
    {
        // Point the cache at a path inside a non-writable parent directory.
        // ensureWarmed should still complete: registry hydrated from discovery,
        // cache write swallowed.
        $impossiblePath = '/proc/cant-write-here/resource-metadata.php';
        $cache    = ResourceMetadataCacheFile::forPath($impossiblePath);
        $registry = $this->freshRegistry();

        $registry->ensureWarmed($this->discovery(), $cache, production: true);

        self::assertTrue($registry->isWarmed());
        self::assertSame(3, count($registry->all()));
    }
}

/**
 * Stub ClassDiscovery for Phase 3d lifecycle tests.
 */
final class InMemoryDiscoveryFor3d extends ClassDiscovery
{
    /** @param list<class-string> $classes */
    public function __construct(private readonly array $classes)
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        if ($attributeClass === ResourceObject::class) {
            return $this->classes;
        }
        return [];
    }
}
