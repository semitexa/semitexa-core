<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\MissingHrefTemplateException;
use Semitexa\Core\Resource\Exception\MissingHrefTemplateValueException;
use Semitexa\Core\Resource\Exception\UnknownResourceRelationException;
use Semitexa\Core\Resource\IriBuilder;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

final class IriBuilderTest extends TestCase
{
    private function buildRegistry(): ResourceMetadataRegistry
    {
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(AddressResource::class));
        $registry->register($extractor->extract(ProfileResource::class));
        $registry->register($extractor->extract(CustomerResource::class));
        return $registry;
    }

    #[Test]
    public function resolves_relation_template_from_metadata(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), 'https://api.example.com');

        $href = $iri->forRelation(CustomerResource::class, ['id' => '123'], 'addresses');
        self::assertSame('https://api.example.com/customers/123/addresses', $href);
    }

    #[Test]
    public function url_encodes_template_values(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), 'https://api.example.com');

        $href = $iri->forRelation(CustomerResource::class, ['id' => 'a/b c'], 'addresses');
        self::assertSame('https://api.example.com/customers/a%2Fb%20c/addresses', $href);
    }

    #[Test]
    public function omits_base_url_when_not_configured(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), '');

        self::assertSame('/customers/7/addresses', $iri->forRelation(CustomerResource::class, ['id' => '7'], 'addresses'));
    }

    #[Test]
    public function rejects_unknown_relation_with_specific_exception(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), '');

        try {
            $iri->forRelation(CustomerResource::class, ['id' => '1'], 'ghost');
            self::fail('Expected UnknownResourceRelationException.');
        } catch (UnknownResourceRelationException $e) {
            self::assertSame('customer', $e->getResourceType());
            self::assertSame('ghost', $e->getRelation());
            self::assertStringContainsString('no field "ghost"', $e->getMessage());
        }
    }

    #[Test]
    public function rejects_relation_without_href_template_with_specific_exception(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), '');

        // 'tags' is ResourceListOf — always-embedded list with no href template.
        try {
            $iri->forRelation(CustomerResource::class, ['id' => '1'], 'tags');
            self::fail('Expected MissingHrefTemplateException.');
        } catch (MissingHrefTemplateException $e) {
            self::assertSame('customer', $e->getResourceType());
            self::assertSame('tags', $e->getRelation());
            self::assertStringContainsString('declares no href template', $e->getMessage());
        }
    }

    #[Test]
    public function rejects_missing_template_value_with_specific_exception(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), '');

        try {
            $iri->forRelation(CustomerResource::class, [], 'addresses');
            self::fail('Expected MissingHrefTemplateValueException.');
        } catch (MissingHrefTemplateValueException $e) {
            self::assertSame('customer', $e->getResourceType());
            self::assertSame('addresses', $e->getRelation());
            self::assertSame('id', $e->getPlaceholder());
            self::assertStringContainsString('missing value for "{id}"', $e->getMessage());
        }
    }

    #[Test]
    public function resolve_handles_arbitrary_templates(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), 'https://api.example.com');

        $href = $iri->resolve('/x/{a}/y/{b}', ['a' => '1', 'b' => '2']);
        self::assertSame('https://api.example.com/x/1/y/2', $href);
    }

    #[Test]
    public function does_not_double_prefix_absolute_urls(): void
    {
        $iri = IriBuilder::forTesting($this->buildRegistry(), 'https://api.example.com');

        $href = $iri->resolve('https://other.example.com/x', []);
        self::assertSame('https://other.example.com/x', $href);
    }

    #[Test]
    public function two_iri_builder_instances_with_different_base_urls_do_not_share_state(): void
    {
        $registry = $this->buildRegistry();
        $a = IriBuilder::forTesting($registry, 'https://a.example.com');
        $b = IriBuilder::forTesting($registry, 'https://b.example.com');

        self::assertStringStartsWith('https://a.example.com/', $a->forRelation(CustomerResource::class, ['id' => '1'], 'addresses'));
        self::assertStringStartsWith('https://b.example.com/', $b->forRelation(CustomerResource::class, ['id' => '1'], 'addresses'));
    }
}
