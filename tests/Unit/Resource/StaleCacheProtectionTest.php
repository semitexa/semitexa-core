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
use Semitexa\Core\Resource\Metadata\ResourceMetadataSourceFingerprint;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\InMemoryDiscoveryFor3d5;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * Phase 3d.5: stale-cache protection. The cache file records the source
 * fingerprint at write time; load returns Stale when it diverges.
 */
final class StaleCacheProtectionTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/semitexa-fp-cache-' . bin2hex(random_bytes(6)) . '.php';
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

    private function fingerprint(ClassDiscovery $discovery): ResourceMetadataSourceFingerprint
    {
        return ResourceMetadataSourceFingerprint::forTesting($discovery);
    }

    private function customerDiscovery(): ClassDiscovery
    {
        return new InMemoryDiscoveryFor3d5([
            AddressResource::class,
            ProfileResource::class,
            CustomerResource::class,
        ]);
    }

    #[Test]
    public function cache_payload_records_fingerprint(): void
    {
        $registry  = $this->freshRegistry();
        $cache     = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discovery = $this->customerDiscovery();
        $fp        = $this->fingerprint($discovery);

        $registry->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);

        $payload = require $this->cachePath;
        self::assertIsArray($payload);
        self::assertArrayHasKey('fingerprint', $payload);
        self::assertIsString($payload['fingerprint']);
        self::assertSame($fp->compute(), $payload['fingerprint']);
    }

    #[Test]
    public function matching_fingerprint_returns_hit(): void
    {
        $cache     = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discovery = $this->customerDiscovery();
        $fp        = $this->fingerprint($discovery);

        // Seed.
        $seed = $this->freshRegistry();
        $seed->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);

        // Reload with the SAME fingerprint → Hit.
        $second = $this->freshRegistry();
        $result = $cache->loadWithResult($second, $fp->compute());
        self::assertSame(CacheLoadResult::Hit, $result);
        self::assertCount(3, $second->all());
    }

    #[Test]
    public function mismatching_fingerprint_returns_stale(): void
    {
        $cache     = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discovery = $this->customerDiscovery();
        $fp        = $this->fingerprint($discovery);

        $seed = $this->freshRegistry();
        $seed->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);

        $second = $this->freshRegistry();
        $result = $cache->loadWithResult($second, 'a-different-fingerprint-' . str_repeat('0', 40));
        self::assertSame(CacheLoadResult::Stale, $result);
    }

    #[Test]
    public function pre_3d5_cache_with_no_fingerprint_field_is_treated_as_stale(): void
    {
        // Write a payload in the OLD pre-3d.5 shape (no `fingerprint` key).
        file_put_contents($this->cachePath, "<?php return ['version' => 1, 'metadata' => []];\n");
        $cache = ResourceMetadataCacheFile::forPath($this->cachePath);

        $registry = $this->freshRegistry();
        $result   = $cache->loadWithResult($registry, 'expected-fingerprint-' . str_repeat('0', 40));

        self::assertSame(CacheLoadResult::Stale, $result);
    }

    #[Test]
    public function null_expected_fingerprint_skips_check_for_legacy_callers(): void
    {
        $cache     = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discovery = $this->customerDiscovery();
        $fp        = $this->fingerprint($discovery);

        $seed = $this->freshRegistry();
        $seed->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);

        $second = $this->freshRegistry();
        $result = $cache->loadWithResult($second, null);
        self::assertSame(CacheLoadResult::Hit, $result);
    }

    #[Test]
    public function corrupt_cache_returns_corrupt_not_stale(): void
    {
        // Wrong shape — version 999, metadata not an array.
        file_put_contents($this->cachePath, "<?php return ['version' => 999, 'metadata' => 'oops'];\n");
        $cache = ResourceMetadataCacheFile::forPath($this->cachePath);

        $registry = $this->freshRegistry();
        $result   = $cache->loadWithResult($registry, 'whatever');
        self::assertSame(CacheLoadResult::Corrupt, $result);
    }

    #[Test]
    public function missing_cache_returns_miss(): void
    {
        $cache  = ResourceMetadataCacheFile::forPath($this->cachePath);
        $result = $cache->loadWithResult($this->freshRegistry(), 'whatever');
        self::assertSame(CacheLoadResult::Miss, $result);
    }

    #[Test]
    public function ensure_warmed_with_stale_fingerprint_rebuilds_and_rewrites(): void
    {
        // First boot writes a cache for class set A.
        $cache       = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discoveryA  = new InMemoryDiscoveryFor3d5([AddressResource::class, ProfileResource::class, CustomerResource::class]);
        $fpA         = $this->fingerprint($discoveryA);
        $first       = $this->freshRegistry();
        $first->ensureWarmed($discoveryA, $cache, production: true, fingerprint: $fpA);

        $payloadA = require $this->cachePath;
        $fpAValue = $payloadA['fingerprint'];

        // Second boot: imagine a deploy added a class. Different discovery
        // → different fingerprint → Stale → rebuild + rewrite.
        $discoveryB = new InMemoryDiscoveryFor3d5([AddressResource::class, ProfileResource::class]);
        $fpB        = $this->fingerprint($discoveryB);
        self::assertNotSame($fpAValue, $fpB->compute(), 'Sanity: different class set must yield different fingerprint.');

        $second = $this->freshRegistry();
        $second->ensureWarmed($discoveryB, $cache, production: true, fingerprint: $fpB);

        // The registry must reflect class set B (only Address + Profile,
        // no Customer), and the cache file must now record fpB.
        self::assertCount(2, $second->all());
        self::assertFalse($second->has(CustomerResource::class));

        $payloadB = require $this->cachePath;
        self::assertSame($fpB->compute(), $payloadB['fingerprint']);
    }

    #[Test]
    public function ensure_warmed_dev_mode_ignores_fingerprint_and_cache(): void
    {
        // Pre-seed a cache that would be a perfect match.
        $cache     = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discovery = $this->customerDiscovery();
        $fp        = $this->fingerprint($discovery);
        $first     = $this->freshRegistry();
        $first->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);

        // Dev boot — must rebuild from discovery (use empty discovery to prove
        // it bypasses the populated cache).
        $dev = $this->freshRegistry();
        $dev->ensureWarmed(
            new InMemoryDiscoveryFor3d5([]),
            $cache,
            production: false,
            fingerprint: $fp,
        );

        self::assertCount(0, $dev->all(), 'Dev mode must rebuild from discovery, ignoring the cache file.');
    }

    #[Test]
    public function ensure_warmed_is_idempotent_after_fingerprint_protected_boot(): void
    {
        $cache     = ResourceMetadataCacheFile::forPath($this->cachePath);
        $discovery = $this->customerDiscovery();
        $fp        = $this->fingerprint($discovery);

        $registry = $this->freshRegistry();
        $registry->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);
        $hash1 = md5(serialize($registry->all()));

        $registry->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);
        $registry->ensureWarmed($discovery, $cache, production: true, fingerprint: $fp);
        $hash2 = md5(serialize($registry->all()));

        self::assertSame($hash1, $hash2);
    }

    // Phase 6m: the pre-3d.5 boolean `ResourceMetadataCacheFile::load()`
    // shim was removed for the v1 release. Tests that exercised the
    // shim path went with it; the equivalent semantics
    // (`loadWithResult($r, null) === CacheLoadResult::Hit`) live in
    // `ResourceMetadataCacheFileTest::dump_then_load_round_trip_preserves_metadata`.
}
