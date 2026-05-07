<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\HandlerProvidedIncludeRegistry;
use Semitexa\Core\Resource\HandlerProvidedIncludeValidator;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * Phase 6c: static lint-time validation of
 * `#[HandlerProvidesResourceIncludes]` declarations against the
 * Resource metadata graph.
 */
final class HandlerProvidedIncludeValidatorTest extends TestCase
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

    #[Test]
    public function valid_declaration_passes(): void
    {
        $registry        = $this->customerRegistry();
        $declarations    = HandlerProvidedIncludeRegistry::withDeclarations([
            'GetCustomerPayload' => [
                'resource' => CustomerResource::class,
                'tokens'   => ['addresses', 'profile'],
            ],
        ]);
        $validator       = HandlerProvidedIncludeValidator::forTesting($registry);

        self::assertSame([], $validator->validate($declarations));
    }

    #[Test]
    public function unknown_resource_class_is_rejected(): void
    {
        $registry     = $this->customerRegistry();
        $declarations = HandlerProvidedIncludeRegistry::withDeclarations([
            'BadPayload' => [
                'resource' => '\\Semitexa\\NotARegistered\\Resource',
                'tokens'   => ['anything'],
            ],
        ]);

        $errors = HandlerProvidedIncludeValidator::forTesting($registry)->validate($declarations);

        self::assertCount(1, $errors);
        self::assertStringContainsString('not a registered Resource', $errors[0]);
    }

    #[Test]
    public function unknown_token_is_rejected(): void
    {
        $registry     = $this->customerRegistry();
        $declarations = HandlerProvidedIncludeRegistry::withDeclarations([
            'BadPayload' => [
                'resource' => CustomerResource::class,
                'tokens'   => ['bogus'],
            ],
        ]);

        $errors = HandlerProvidedIncludeValidator::forTesting($registry)->validate($declarations);

        self::assertCount(1, $errors);
        self::assertStringContainsString('does not exist', $errors[0]);
        self::assertStringContainsString('"bogus"', $errors[0]);
    }

    #[Test]
    public function scalar_token_is_rejected(): void
    {
        // `name` is a scalar field on CustomerResource — declaring it
        // handler-provided makes no sense.
        $registry     = $this->customerRegistry();
        $declarations = HandlerProvidedIncludeRegistry::withDeclarations([
            'BadPayload' => [
                'resource' => CustomerResource::class,
                'tokens'   => ['name'],
            ],
        ]);

        $errors = HandlerProvidedIncludeValidator::forTesting($registry)->validate($declarations);

        self::assertCount(1, $errors);
        self::assertStringContainsString('not a relation', $errors[0]);
    }

    #[Test]
    public function dot_notation_walks_nested_targets(): void
    {
        // `addresses.country` would walk into AddressResource which has
        // no `country` field; should fail loud.
        $registry     = $this->customerRegistry();
        $declarations = HandlerProvidedIncludeRegistry::withDeclarations([
            'BadPayload' => [
                'resource' => CustomerResource::class,
                'tokens'   => ['addresses.country'],
            ],
        ]);

        $errors = HandlerProvidedIncludeValidator::forTesting($registry)->validate($declarations);

        self::assertCount(1, $errors);
        self::assertStringContainsString('addresses.country', $errors[0]);
        self::assertStringContainsString('"country"', $errors[0]);
    }

    #[Test]
    public function tokens_are_normalized_lowercase_deduped_sorted(): void
    {
        $declarations = HandlerProvidedIncludeRegistry::withDeclarations([
            'P' => [
                'resource' => CustomerResource::class,
                'tokens'   => ['profile', 'ADDRESSES', '  profile  ', 'addresses', ''],
            ],
        ]);

        self::assertSame(['addresses', 'profile'], $declarations->tokensFor('P'));
    }

    #[Test]
    public function unknown_payload_lookup_returns_empty_list(): void
    {
        $declarations = HandlerProvidedIncludeRegistry::withDeclarations([]);

        self::assertSame([], $declarations->tokensFor('NotRegistered'));
        self::assertNull($declarations->resourceFor('NotRegistered'));
    }
}
