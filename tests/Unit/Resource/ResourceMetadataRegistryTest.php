<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\MalformedResourceObjectException;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

final class ResourceMetadataRegistryTest extends TestCase
{
    #[Test]
    public function registers_and_retrieves_metadata_by_class_and_type(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $registry->register($extractor->extract(AddressResource::class));

        self::assertTrue($registry->has(AddressResource::class));
        self::assertSame('address', $registry->require(AddressResource::class)->type);
        self::assertSame(AddressResource::class, $registry->findByType('address')?->class);
    }

    #[Test]
    public function require_throws_for_unregistered_class(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No ResourceObjectMetadata registered/');
        $registry->require(AddressResource::class);
    }

    #[Test]
    public function rejects_duplicate_type_registration(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $registry->register(new ResourceObjectMetadata(
            class: AddressResource::class,
            type: 'address',
            idField: 'id',
            fields: ['id' => new ResourceFieldMetadata(name: 'id', kind: ResourceFieldKind::Scalar, nullable: false)],
        ));

        // Use a different class but the same type — should fail.
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/declared twice/');
        $registry->register(new ResourceObjectMetadata(
            class: ProfileResource::class,
            type: 'address',
            idField: 'id',
            fields: ['id' => new ResourceFieldMetadata(name: 'id', kind: ResourceFieldKind::Scalar, nullable: false)],
        ));
    }

    #[Test]
    public function reset_clears_all_registrations(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        self::assertCount(3, $registry->all());
        $registry->reset();
        self::assertSame([], $registry->all());
    }

    #[Test]
    public function returns_null_for_unknown_type(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        self::assertNull($registry->findByType('not-registered'));
        self::assertNull($registry->get(AddressResource::class));
    }
}
