<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Exception\GraphqlSelectionDepthExceededException;
use Semitexa\Core\Resource\Exception\UnknownGraphqlFieldException;
use Semitexa\Core\Resource\GraphqlSelectionParser;
use Semitexa\Core\Resource\GraphqlSelectionToIncludeSet;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

// Phase 5c three-level depth fixtures (Customer5c → Address5c → Country5c
// with expandable relations between them) — used only by the
// nested-too-deep test below. Inline so they are PSR-4 autoloaded with the
// test file.
#[ResourceObject(type: 'phase5c.country')]
final readonly class CountryResourceFixture5c implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId] public string $id,
        #[ResourceField] public string $name,
    ) {}
}

#[ResourceObject(type: 'phase5c.address')]
final readonly class AddressResourceFixture5c implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId] public string $id,
        #[ResourceField] public string $city,
        #[ResourceRefAttr(target: CountryResourceFixture5c::class, expandable: true, include: 'country', href: '/addresses/{id}/country')]
        public ?ResourceRef $country = null,
    ) {}
}

#[ResourceObject(type: 'phase5c.customer')]
final readonly class CustomerResourceFixture5c implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId] public string $id,
        #[ResourceField] public string $name,
        #[ResourceRefListAttr(target: AddressResourceFixture5c::class, expandable: true, include: 'addresses', href: '/customers/{id}/addresses')]
        public ResourceRefList $addresses,
    ) {}
}

final class GraphqlSelectionToIncludeSetTest extends TestCase
{
    private GraphqlSelectionToIncludeSet $bridge;
    private GraphqlSelectionParser $parser;
    private ResourceMetadataRegistry $registry;

    protected function setUp(): void
    {
        $extractor      = new ResourceMetadataExtractor();
        $this->registry = ResourceMetadataRegistry::forTesting($extractor);
        $this->registry->register($extractor->extract(AddressResource::class));
        $this->registry->register($extractor->extract(ProfileResource::class));
        $this->registry->register($extractor->extract(CustomerResource::class));
        $this->bridge = GraphqlSelectionToIncludeSet::forTesting($this->registry);
        $this->parser = new GraphqlSelectionParser();
    }

    /** @return list<string> */
    private function tokensFor(string $query): array
    {
        $rootField = $this->parser->parse($query)->singleRootField();
        $rootMeta  = $this->registry->require(CustomerResource::class);
        return $this->bridge->translate($rootField, $rootMeta)->tokens;
    }

    #[Test]
    public function only_scalars_yield_empty_include_set(): void
    {
        self::assertSame([], $this->tokensFor('{ customer { id name } }'));
    }

    #[Test]
    public function expandable_to_one_relation_becomes_include_token(): void
    {
        self::assertSame(['profile'], $this->tokensFor('{ customer { id profile { id bio } } }'));
    }

    #[Test]
    public function expandable_to_many_relation_becomes_include_token(): void
    {
        self::assertSame(['addresses'], $this->tokensFor('{ customer { id addresses { id city } } }'));
    }

    #[Test]
    public function multiple_selected_relations_collapse_and_sort(): void
    {
        self::assertSame(['addresses', 'profile'], $this->tokensFor(
            '{ customer { profile { id } addresses { id } id } }',
        ));
    }

    #[Test]
    public function selecting_a_relation_without_subselection_still_emits_token(): void
    {
        // Parser allows leaf-style relation selection (no `{}`); the
        // translator treats it the same as a sub-selected relation: an
        // include token with no nested children.
        self::assertSame(['profile'], $this->tokensFor('{ customer { id profile } }'));
    }

    #[Test]
    public function duplicate_relation_selections_collapse_to_one_token(): void
    {
        self::assertSame(['addresses'], $this->tokensFor(
            '{ customer { addresses { id } addresses { city } } }',
        ));
    }

    #[Test]
    public function unknown_field_returns_400(): void
    {
        try {
            $this->tokensFor('{ customer { id orders } }');
            self::fail('Expected UnknownGraphqlFieldException');
        } catch (UnknownGraphqlFieldException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame('orders', $e->getErrorContext()['field']);
            self::assertSame('customer', $e->getErrorContext()['resource']);
        }
    }

    #[Test]
    public function scalar_with_subselection_returns_400(): void
    {
        try {
            $this->tokensFor('{ customer { name { id } } }');
            self::fail('Expected UnknownGraphqlFieldException');
        } catch (UnknownGraphqlFieldException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertStringContainsString('cannot have a selection set', $e->getMessage());
        }
    }

    #[Test]
    public function nested_selection_beyond_max_depth_returns_400(): void
    {
        // Use the Phase 3c three-level fixtures (Customer → Address → Country)
        // so the field exists on every level — the depth-exceeded check is
        // therefore exercised cleanly, not an incidental unknown-field error.
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);
        $registry->register($extractor->extract(CountryResourceFixture5c::class));
        $registry->register($extractor->extract(AddressResourceFixture5c::class));
        $registry->register($extractor->extract(CustomerResourceFixture5c::class));

        $bridge   = GraphqlSelectionToIncludeSet::forTesting($registry);
        $rootField = $this->parser->parse('{ customer { addresses { country { id } } } }')->singleRootField();

        try {
            $bridge->translate(
                $rootField,
                $registry->require(CustomerResourceFixture5c::class),
            );
            self::fail('Expected GraphqlSelectionDepthExceededException');
        } catch (GraphqlSelectionDepthExceededException $e) {
            self::assertSame(400, $e->getStatusCode()->value);
            self::assertSame(1, $e->getErrorContext()['max_depth']);
            self::assertStringContainsString('country', $e->getErrorContext()['offending_path']);
        }
    }

    #[Test]
    public function tokens_are_sorted_deterministically(): void
    {
        // Three runs over the same input must produce the same token order.
        $q = '{ customer { profile { id } addresses { id } } }';
        $a = $this->tokensFor($q);
        $b = $this->tokensFor($q);
        $c = $this->tokensFor($q);
        self::assertSame($a, $b);
        self::assertSame($b, $c);
        self::assertSame(['addresses', 'profile'], $a);
    }

    #[Test]
    public function does_not_mutate_metadata_registry(): void
    {
        $hash = md5(serialize($this->registry->all()));
        $this->tokensFor('{ customer { id profile { id } addresses { id } } }');
        $this->tokensFor('{ customer { id profile { id } addresses { id } } }');
        self::assertSame($hash, md5(serialize($this->registry->all())));
    }
}
