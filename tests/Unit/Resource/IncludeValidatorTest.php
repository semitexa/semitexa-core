<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\NonExpandableIncludeException;
use Semitexa\Core\Resource\Exception\UnknownIncludeException;
use Semitexa\Core\Resource\Exception\UnsatisfiedResourceIncludeException;
use Semitexa\Core\Resource\HandlerProvidedIncludeRegistry;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AliasProfileCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\BotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ResolvableCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UserResource;

final class IncludeValidatorTest extends TestCase
{
    private function customerRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));
        return $registry;
    }

    private function aliasCustomerRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(AliasProfileCustomerResource::class));
        return $registry;
    }

    private function nestedResolvableRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(PreferencesResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(ResolvableCustomerResource::class));
        return $registry;
    }

    #[Test]
    public function empty_include_set_passes(): void
    {
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        $validator->validate(IncludeSet::empty(), $registry->require(CustomerResource::class));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function valid_top_level_include_passes(): void
    {
        // An expandable token is satisfiable only when a
        // resolver is registered or the route declares it
        // handler-provided. This test exercises the handler-provided
        // path; the resolver path is covered by the dedicated satisfiability
        // tests below.
        $registry             = $this->customerRegistry();
        $handlerProvided      = HandlerProvidedIncludeRegistry::withDeclarations([
            CustomerResource::class => ['resource' => CustomerResource::class, 'tokens' => ['addresses']],
        ]);
        $validator            = IncludeValidator::forTesting($registry, $handlerProvided);

        $validator->validate(
            IncludeSet::fromQueryString('addresses'),
            $registry->require(CustomerResource::class),
            CustomerResource::class,
        );
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function include_token_uses_declared_public_name_not_php_property_name(): void
    {
        $registry        = $this->aliasCustomerRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([
            AliasProfileCustomerResource::class => ['resource' => AliasProfileCustomerResource::class, 'tokens' => ['profile']],
        ]);
        $validator = IncludeValidator::forTesting($registry, $handlerProvided);

        $validator->validate(
            IncludeSet::fromQueryString('profile'),
            $registry->require(AliasProfileCustomerResource::class),
            AliasProfileCustomerResource::class,
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function unknown_include_throws_400(): void
    {
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        try {
            $validator->validate(IncludeSet::fromQueryString('orders'), $registry->require(CustomerResource::class));
            self::fail('Expected UnknownIncludeException.');
        } catch (UnknownIncludeException $e) {
            self::assertStringContainsString('orders', $e->getMessage());
            self::assertSame(400, $e->getStatusCode()->value);
        }
    }

    #[Test]
    public function include_targeting_a_scalar_field_is_unknown(): void
    {
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        $this->expectException(UnknownIncludeException::class);
        $validator->validate(IncludeSet::fromQueryString('name'), $registry->require(CustomerResource::class));
    }

    #[Test]
    public function dot_notation_navigates_through_relation_targets(): void
    {
        // The parent relation must itself be satisfiable before nested
        // traversal continues. Customer.addresses is expandable but has no
        // resolver and no handler-provided declaration here, so the dotted
        // token fails at the first segment.
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        $this->expectException(UnsatisfiedResourceIncludeException::class);
        $validator->validate(IncludeSet::fromQueryString('addresses.country'), $registry->require(CustomerResource::class));
    }

    #[Test]
    public function union_relation_include_passes_via_first_registered_target(): void
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(UserResource::class));
        $registry->register($extractor->extract(BotResource::class));
        $registry->register($extractor->extract(CommentResource::class));

        // Handler-provided declaration covers the union
        // relation so the existing structural test remains green.
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([
            CommentResource::class => ['resource' => CommentResource::class, 'tokens' => ['mentions']],
        ]);
        $validator = IncludeValidator::forTesting($registry, $handlerProvided);
        $validator->validate(
            IncludeSet::fromQueryString('mentions'),
            $registry->require(CommentResource::class),
            CommentResource::class,
        );
        $this->expectNotToPerformAssertions();
    }

    // ----- Dotted (nested) include validation ----------------

    #[Test]
    public function dotted_resolver_backed_nested_token_passes_without_handler_declaration(): void
    {
        // `profile.preferences` walks ResolvableCustomer.profile →
        // Profile.preferences. Both hops are resolver-backed on these
        // fixtures, so the nested token is satisfiable without a
        // handler-provided declaration.
        $registry  = $this->nestedResolvableRegistry();
        $validator = IncludeValidator::forTesting($registry);

        $validator->validate(
            IncludeSet::fromQueryString('profile.preferences'),
            $registry->require(ResolvableCustomerResource::class),
        );
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function dotted_token_with_unknown_leaf_throws_400(): void
    {
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        try {
            $validator->validate(
                IncludeSet::fromQueryString('profile.unknown'),
                $registry->require(CustomerResource::class),
            );
            self::fail('Expected UnsatisfiedResourceIncludeException.');
        } catch (UnsatisfiedResourceIncludeException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame('profile.unknown', $e->token);
            self::assertSame('profile', $e->relationName);
        }
    }

    #[Test]
    public function dotted_token_through_scalar_segment_throws_400(): void
    {
        // CustomerResource::$name is a scalar — a dotted token
        // `name.preferences` cannot be resolved through it.
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        try {
            $validator->validate(
                IncludeSet::fromQueryString('name.preferences'),
                $registry->require(CustomerResource::class),
            );
            self::fail('Expected UnknownIncludeException.');
        } catch (UnknownIncludeException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
        }
    }
}
