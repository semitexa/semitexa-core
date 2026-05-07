<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\MalformedResourceObjectException;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedConflictingAttributesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedDuplicateIdResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedIntIdResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedNonReadonlyResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedRefListTypeResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedRefTypeResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedUnionEmptyTargetsResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedUnionTypeMismatchResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedResolverOnScalarResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\OptionalRefResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ResolvableCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\StubRelationResolver;

final class ResourceMetadataExtractorTest extends TestCase
{
    #[Test]
    public function extracts_basic_resource_with_string_id(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(AddressResource::class);

        self::assertSame(AddressResource::class, $metadata->class);
        self::assertSame('address', $metadata->type);
        self::assertSame('id', $metadata->idField);
        self::assertCount(3, $metadata->fields);
        self::assertSame(ResourceFieldKind::Scalar, $metadata->fields['id']->kind);
        self::assertSame(ResourceFieldKind::Scalar, $metadata->fields['city']->kind);
    }

    #[Test]
    public function extracts_relations_and_embedded_lists(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(CustomerResource::class);

        self::assertSame('customer', $metadata->type);
        self::assertSame('id', $metadata->idField);

        $profile = $metadata->getField('profile');
        self::assertNotNull($profile);
        self::assertSame(ResourceFieldKind::RefOne, $profile->kind);
        self::assertTrue($profile->expandable);
        self::assertSame('/customers/{id}/profile', $profile->hrefTemplate);

        $addresses = $metadata->getField('addresses');
        self::assertNotNull($addresses);
        self::assertSame(ResourceFieldKind::RefMany, $addresses->kind);
        self::assertSame('addresses', $addresses->include);

        $tags = $metadata->getField('tags');
        self::assertNotNull($tags);
        self::assertSame(ResourceFieldKind::EmbeddedMany, $tags->kind);
        self::assertSame(AddressResource::class, $tags->target);
    }

    #[Test]
    public function rejects_non_final_readonly_class(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/must be declared `final readonly`/');
        $extractor->extract(MalformedNonReadonlyResource::class);
    }

    #[Test]
    public function rejects_non_string_resource_id(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/must be `string`/');
        $extractor->extract(MalformedIntIdResource::class);
    }

    #[Test]
    public function rejects_resource_ref_attribute_on_wrong_php_type(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/declares #\[ResourceRef\] but its PHP type is string/');
        $extractor->extract(MalformedRefTypeResource::class);
    }

    #[Test]
    public function rejects_resource_ref_list_on_array_typed_property(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/declares #\[ResourceRefList\] but its PHP type is array/');
        $extractor->extract(MalformedRefListTypeResource::class);
    }

    #[Test]
    public function rejects_multiple_resource_ids(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/declares more than one #\[ResourceId\]/');
        $extractor->extract(MalformedDuplicateIdResource::class);
    }

    #[Test]
    public function rejects_conflicting_resource_attributes(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/declares both .* only one resource attribute/');
        $extractor->extract(MalformedConflictingAttributesResource::class);
    }

    #[Test]
    public function rejects_union_with_empty_targets(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/#\[ResourceUnion\] without targets/');
        $extractor->extract(MalformedUnionEmptyTargetsResource::class);
    }

    #[Test]
    public function rejects_union_list_typed_as_resource_ref(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $this->expectException(MalformedResourceObjectException::class);
        $this->expectExceptionMessageMatches('/ResourceUnion\(list: true\)/');
        $extractor->extract(MalformedUnionTypeMismatchResource::class);
    }

    #[Test]
    public function extracts_optional_relation_with_nullable_envelope(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(OptionalRefResource::class);

        $profile = $metadata->getField('profile');
        self::assertNotNull($profile);
        self::assertSame(ResourceFieldKind::RefOne, $profile->kind);
        self::assertTrue($profile->nullable, 'Optional relations carry nullable=true derived from PHP type.');
        self::assertFalse($profile->isList());
    }

    #[Test]
    public function extracts_union_one_and_union_many(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(CommentResource::class);

        $author = $metadata->getField('author');
        self::assertNotNull($author);
        self::assertSame(ResourceFieldKind::Union, $author->kind);
        self::assertFalse($author->isList());
        self::assertNotNull($author->unionTargets);
        self::assertCount(2, $author->unionTargets);
        self::assertSame('type', $author->discriminator);

        $mentions = $metadata->getField('mentions');
        self::assertNotNull($mentions);
        self::assertSame(ResourceFieldKind::Union, $mentions->kind);
        self::assertTrue($mentions->isList());
        self::assertTrue($mentions->expandable);
    }

    #[Test]
    public function lists_and_embedded_lists_report_isList_true(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(CustomerResource::class);

        self::assertTrue($metadata->getField('addresses')?->isList());
        self::assertTrue($metadata->getField('tags')?->isList());
        self::assertFalse($metadata->getField('profile')?->isList());
        self::assertFalse($metadata->getField('id')?->isList());
    }

    #[Test]
    public function address_resource_extraction_is_deterministic(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $first  = $extractor->extract(AddressResource::class);
        $second = $extractor->extract(AddressResource::class);

        self::assertEquals($first, $second, 'Extraction result must be deterministic.');
    }

    #[Test]
    public function relations_without_resolve_with_have_null_resolver_class(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(CustomerResource::class);

        self::assertNull($metadata->getField('profile')?->resolverClass);
        self::assertNull($metadata->getField('addresses')?->resolverClass);
        self::assertNull($metadata->getField('id')?->resolverClass);
        self::assertNull($metadata->getField('name')?->resolverClass);
    }

    #[Test]
    public function resolve_with_attribute_is_extracted_into_resolver_class(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(ResolvableCustomerResource::class);

        $profile = $metadata->getField('profile');
        self::assertNotNull($profile);
        self::assertSame(StubRelationResolver::class, $profile->resolverClass);

        // Sibling relation without #[ResolveWith] keeps a null resolverClass.
        $addresses = $metadata->getField('addresses');
        self::assertNotNull($addresses);
        self::assertNull($addresses->resolverClass);
    }

    #[Test]
    public function resolve_with_on_scalar_field_is_carried_through_for_validator_to_reject(): void
    {
        // Phase 6b decision: the extractor records `resolverClass` on every
        // kind so the validator can produce a precise error message; the
        // extractor does not refuse the structure itself.
        $extractor = new ResourceMetadataExtractor();
        $metadata  = $extractor->extract(MalformedResolverOnScalarResource::class);

        $name = $metadata->getField('name');
        self::assertNotNull($name);
        self::assertSame(StubRelationResolver::class, $name->resolverClass);
    }
}
