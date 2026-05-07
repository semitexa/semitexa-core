<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceMetadataValidator;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\BotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedDomainFactoryResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedHrefTemplateResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedResolverMissingClassResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedResolverOnScalarResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedResolverWithoutAsServiceResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\MalformedResolverWrongInterfaceResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ResolvableCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UserResource;

final class ResourceMetadataValidatorTest extends TestCase
{
    #[Test]
    public function customer_address_profile_graph_is_valid(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        self::assertSame([], array_map(fn ($e) => $e->getMessage(), $errors));
    }

    #[Test]
    public function relation_target_without_resource_id_is_rejected(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        // Register only Customer and Profile but NOT Address. Customer references Address as a target,
        // and the validator should flag the unknown target.
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('AddressResource', $messages[0]);
        self::assertStringContainsString('not a registered Resource', $messages[0]);
    }

    #[Test]
    public function union_targets_must_be_registered(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        // Comment.author / .mentions reference UserResource and BotResource — only register one.
        $registry->register($extractor->extract(UserResource::class));
        $registry->register($extractor->extract(CommentResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = implode("\n", array_map(fn ($e) => $e->getMessage(), $errors));
        self::assertStringContainsString('BotResource', $messages);
        self::assertStringContainsString('not a registered Resource', $messages);
    }

    #[Test]
    public function valid_union_graph_passes(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $registry->register($extractor->extract(UserResource::class));
        $registry->register($extractor->extract(BotResource::class));
        $registry->register($extractor->extract(CommentResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        self::assertSame([], array_map(fn ($e) => $e->getMessage(), $errors));
    }

    #[Test]
    public function rejects_static_domain_factory_on_resource_dto(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(MalformedDomainFactoryResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('fromDomain', $messages[0]);
        self::assertStringContainsString('Use a Projector', $messages[0]);
    }

    #[Test]
    public function rejects_href_template_field_that_does_not_exist(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(MalformedHrefTemplateResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('{tenantId}', $messages[0]);
    }

    #[Test]
    public function resolve_with_on_valid_relation_passes_validation(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(ResolvableCustomerResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        self::assertSame([], array_map(fn ($e) => $e->getMessage(), $errors));
    }

    #[Test]
    public function rejects_resolve_with_on_scalar_field(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(MalformedResolverOnScalarResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('#[ResolveWith]', $messages[0]);
        self::assertStringContainsString('not a relation', $messages[0]);
    }

    #[Test]
    public function rejects_resolve_with_pointing_at_missing_class(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(MalformedResolverMissingClassResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('NonExistentResolverClass', $messages[0]);
        self::assertStringContainsString('does not exist', $messages[0]);
    }

    #[Test]
    public function rejects_resolve_with_pointing_at_class_that_does_not_implement_interface(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(MalformedResolverWrongInterfaceResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('AddressResource', $messages[0]);
        self::assertStringContainsString('RelationResolverInterface', $messages[0]);
    }

    #[Test]
    public function rejects_resolve_with_pointing_at_class_without_as_service(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(MalformedResolverWithoutAsServiceResource::class));

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        $messages = array_map(fn ($e) => $e->getMessage(), $errors);
        self::assertCount(1, $messages);
        self::assertStringContainsString('StubResolverWithoutAsService', $messages[0]);
        self::assertStringContainsString('AsService', $messages[0]);
    }
}
