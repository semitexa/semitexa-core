<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\UnknownIncludeException;
use Semitexa\Core\Resource\Exception\UnsatisfiedResourceIncludeException;
use Semitexa\Core\Resource\HandlerProvidedIncludeRegistry;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\IncludeValidator;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ResolvableCustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ResolvableProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\StubRelationResolver;

/**
 * Phase 6c: include-token satisfiability rules.
 *
 * A requested expandable token is satisfiable when:
 *   1. its leaf relation has `#[ResolveWith]`, OR
 *   2. the route's payload class is registered in the
 *      `HandlerProvidedIncludeRegistry` and lists the token.
 *
 * Anything else fails with `UnsatisfiedResourceIncludeException`
 * (HTTP 400). Unknown / scalar / non-expandable tokens still fail
 * with their existing exceptions.
 */
final class Phase6cIncludeSatisfiabilityTest extends TestCase
{
    private function customerRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));
        return $registry;
    }

    private function resolvableRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(ResolvableProfileResource::class));
        return $registry;
    }

    private function nestedResolvableRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(ResolvableCustomerResource::class));
        return $registry;
    }

    #[Test]
    public function relation_with_resolve_with_passes_without_handler_provided_opt_in(): void
    {
        $registry        = $this->resolvableRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([]);
        $validator       = IncludeValidator::forTesting($registry, $handlerProvided);

        $validator->validate(
            IncludeSet::fromQueryString('profile'),
            $registry->require(ResolvableProfileResource::class),
            ResolvableProfileResource::class,
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function relation_without_resolve_with_fails_unless_handler_provided(): void
    {
        $registry        = $this->customerRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([]);
        $validator       = IncludeValidator::forTesting($registry, $handlerProvided);

        try {
            $validator->validate(
                IncludeSet::fromQueryString('addresses'),
                $registry->require(CustomerResource::class),
                CustomerResource::class,
            );
            self::fail('Expected UnsatisfiedResourceIncludeException.');
        } catch (UnsatisfiedResourceIncludeException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame('addresses', $e->token);
            self::assertSame('addresses', $e->relationName);
            self::assertSame('customer', $e->resourceType);
            self::assertTrue($e->resolverMissing);
            self::assertTrue($e->handlerContractMissing);
        }
    }

    #[Test]
    public function relation_with_resolve_with_and_handler_provided_also_passes(): void
    {
        $registry        = $this->resolvableRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([
            ResolvableProfileResource::class => [
                'resource' => ResolvableProfileResource::class,
                'tokens'   => ['profile'],
            ],
        ]);
        $validator = IncludeValidator::forTesting($registry, $handlerProvided);

        $validator->validate(
            IncludeSet::fromQueryString('profile'),
            $registry->require(ResolvableProfileResource::class),
            ResolvableProfileResource::class,
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function dotted_include_requires_intermediate_segment_to_be_satisfiable(): void
    {
        $registry        = $this->customerRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([]);
        $validator       = IncludeValidator::forTesting($registry, $handlerProvided);

        $this->expectException(UnsatisfiedResourceIncludeException::class);
        $validator->validate(
            IncludeSet::fromQueryString('profile.preferences'),
            $registry->require(CustomerResource::class),
            CustomerResource::class,
        );
    }

    #[Test]
    public function dotted_include_passes_when_parent_and_leaf_are_satisfiable(): void
    {
        $registry        = $this->nestedResolvableRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([]);
        $validator       = IncludeValidator::forTesting($registry, $handlerProvided);

        $validator->validate(
            IncludeSet::fromQueryString('profile.preferences'),
            $registry->require(ResolvableCustomerResource::class),
            ResolvableCustomerResource::class,
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function unknown_token_still_fails_400_with_unknown_exception_not_unsatisfied(): void
    {
        $registry        = $this->customerRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([
            CustomerResource::class => [
                'resource' => CustomerResource::class,
                'tokens'   => ['addresses', 'profile'],
            ],
        ]);
        $validator = IncludeValidator::forTesting($registry, $handlerProvided);

        // The leaf-existence check runs before the satisfiability check,
        // so unknown tokens always surface as UnknownIncludeException.
        try {
            $validator->validate(
                IncludeSet::fromQueryString('orders'),
                $registry->require(CustomerResource::class),
            CustomerResource::class,
        );
            self::fail('Expected UnknownIncludeException.');
        } catch (UnknownIncludeException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
        }
    }

    #[Test]
    public function exception_carries_full_context(): void
    {
        $registry        = $this->customerRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([]);
        $validator       = IncludeValidator::forTesting($registry, $handlerProvided);

        try {
            $validator->validate(
                IncludeSet::fromQueryString('profile'),
                $registry->require(CustomerResource::class),
                CustomerResource::class,
            );
            self::fail('Expected UnsatisfiedResourceIncludeException.');
        } catch (UnsatisfiedResourceIncludeException $e) {
            $context = $e->getErrorContext();
            self::assertSame('profile', $context['token']);
            self::assertSame('customer', $context['resource']);
            self::assertSame('profile', $context['relation']);
            self::assertTrue($context['resolver_missing']);
            self::assertTrue($context['handler_contract_missing']);
        }
    }

    #[Test]
    public function null_payload_class_means_no_handler_provided_fallback(): void
    {
        // Calling validate() without a payload class is the legacy
        // pre-Phase-6c entrypoint. Without a resolver, satisfiability
        // must still fail loud — no silent acceptance.
        $registry  = $this->customerRegistry();
        $validator = IncludeValidator::forTesting($registry);

        $this->expectException(UnsatisfiedResourceIncludeException::class);
        $validator->validate(
            IncludeSet::fromQueryString('addresses'),
            $registry->require(CustomerResource::class),
        );
    }

    #[Test]
    public function repeated_validation_does_not_leak_state(): void
    {
        $registry        = $this->resolvableRegistry();
        $handlerProvided = HandlerProvidedIncludeRegistry::withDeclarations([]);
        $validator       = IncludeValidator::forTesting($registry, $handlerProvided);
        $rootMeta        = $registry->require(ResolvableProfileResource::class);

        for ($i = 0; $i < 50; $i++) {
            $validator->validate(IncludeSet::fromQueryString('profile'), $rootMeta, ResolvableProfileResource::class);
        }

        // Type-check: the resolver type referenced by the fixture must be
        // the documented contract, not GraphQL or anything else.
        self::assertTrue(
            (new \ReflectionClass(StubRelationResolver::class))
                ->implementsInterface(RelationResolverInterface::class),
        );
    }
}
