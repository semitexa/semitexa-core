<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Metadata\CacheLoadResult;
use Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\BotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ResolvableCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\StubRelationResolver;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UserResource;

final class ResourceMetadataCacheFileTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . '/semitexa-resource-meta-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    #[Test]
    public function load_returns_miss_when_cache_is_absent(): void
    {
        $cache    = ResourceMetadataCacheFile::forPath($this->tempPath);
        $registry = ResourceMetadataRegistry::forTesting(new ResourceMetadataExtractor());

        self::assertFalse($cache->exists());
        self::assertSame(CacheLoadResult::Miss, $cache->loadWithResult($registry, null));
    }

    #[Test]
    public function dump_then_load_round_trip_preserves_metadata(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $original  = ResourceMetadataRegistry::forTesting($extractor);
        $original->register($extractor->extract(AddressResource::class));
        $original->register($extractor->extract(ProfileResource::class));
        $original->register($extractor->extract(CustomerResource::class));
        $original->register($extractor->extract(UserResource::class));
        $original->register($extractor->extract(BotResource::class));
        $original->register($extractor->extract(CommentResource::class));

        $cache = ResourceMetadataCacheFile::forPath($this->tempPath);
        $cache->dump($original);

        self::assertTrue($cache->exists());

        $rehydrated = ResourceMetadataRegistry::forTesting($extractor);
        self::assertSame(CacheLoadResult::Hit, $cache->loadWithResult($rehydrated, null));

        self::assertCount(6, $rehydrated->all());

        $customer = $rehydrated->get(CustomerResource::class);
        self::assertNotNull($customer);
        self::assertSame('customer', $customer->type);
        self::assertSame('id', $customer->idField);
        self::assertTrue($customer->getField('addresses')?->isList());
        self::assertSame('addresses', $customer->getField('addresses')?->include);

        $comment = $rehydrated->get(CommentResource::class);
        self::assertNotNull($comment);
        self::assertSame(['Semitexa\\Core\\Tests\\Unit\\Resource\\Fixtures\\UserResource', 'Semitexa\\Core\\Tests\\Unit\\Resource\\Fixtures\\BotResource'], $comment->getField('author')?->unionTargets);
    }

    #[Test]
    public function load_returns_corrupt_when_payload_version_is_unsupported(): void
    {
        file_put_contents($this->tempPath, "<?php\nreturn ['version' => 999, 'metadata' => []];\n");

        $cache    = ResourceMetadataCacheFile::forPath($this->tempPath);
        $registry = ResourceMetadataRegistry::forTesting(new ResourceMetadataExtractor());

        self::assertSame(CacheLoadResult::Corrupt, $cache->loadWithResult($registry, null));
    }

    #[Test]
    public function delete_removes_the_file(): void
    {
        file_put_contents($this->tempPath, "<?php return ['version' => 1, 'metadata' => []];\n");
        $cache = ResourceMetadataCacheFile::forPath($this->tempPath);

        self::assertTrue($cache->exists());
        self::assertTrue($cache->delete());
        self::assertFalse($cache->exists());
        self::assertFalse($cache->delete());
    }

    #[Test]
    public function dump_then_load_round_trip_preserves_resolver_class(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $original  = ResourceMetadataRegistry::forTesting($extractor);
        $original->register($extractor->extract(AddressResource::class));
        $original->register($extractor->extract(ProfileResource::class));
        $original->register($extractor->extract(ResolvableCustomerResource::class));

        $cache = ResourceMetadataCacheFile::forPath($this->tempPath);
        $cache->dump($original);

        $rehydrated = ResourceMetadataRegistry::forTesting($extractor);
        // Phase 6c hygiene: prefer the non-deprecated `loadWithResult()`.
        self::assertSame(CacheLoadResult::Hit, $cache->loadWithResult($rehydrated));

        $customer = $rehydrated->get(ResolvableCustomerResource::class);
        self::assertNotNull($customer);
        self::assertSame(StubRelationResolver::class, $customer->getField('profile')?->resolverClass);
        // Sibling relation without #[ResolveWith] survives as null.
        self::assertNull($customer->getField('addresses')?->resolverClass);
    }
}
