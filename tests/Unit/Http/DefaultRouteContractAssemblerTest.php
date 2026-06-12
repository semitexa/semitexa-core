<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Contract\RouteContractBlockContributorInterface;
use Semitexa\Core\Http\DefaultRouteContractAssembler;
use Semitexa\Core\Http\PayloadMetadataReflector;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;
use Semitexa\Core\Resource\RenderProfile;

/**
 * One Way Pattern — Phase 1: the default assembler joins the reflector's
 * document, the resource registry, and contributed blocks — additively,
 * with the design's degradation rules.
 */
final class DefaultRouteContractAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        PayloadMetadataReflector::clearCache();
    }

    private function registryWithResource(): ResourceMetadataRegistry
    {
        $registry = ResourceMetadataRegistry::forTesting(new ResourceMetadataExtractor());
        $registry->register(new ResourceObjectMetadata(
            class: AssemblerResourceFixture::class,
            type: 'assembler.fixture',
            idField: 'id',
            fields: [
                'id' => new ResourceFieldMetadata(
                    name: 'id',
                    kind: ResourceFieldKind::Scalar,
                    nullable: false,
                ),
            ],
        ));

        return $registry;
    }

    private static function collectionContributor(): RouteContractBlockContributorInterface
    {
        return new class implements RouteContractBlockContributorInterface {
            public function contributeBlocks(string $payloadClass, ?string $responseClass): array
            {
                return ['collection' => ['sort' => ['fields' => ['id']]]];
            }

            public function resolveResourceClass(?string $responseClass): ?string
            {
                return $responseClass === AssemblerResponseFixture::class
                    ? AssemblerResourceFixture::class
                    : null;
            }
        };
    }

    #[Test]
    public function joins_input_collection_and_output(): void
    {
        $assembler = DefaultRouteContractAssembler::forTesting(
            $this->registryWithResource(),
            [self::collectionContributor()],
        );

        $document = $assembler
            ->assemble(AssemblerPayloadFixture::class, AssemblerResponseFixture::class)
            ->toDocument();

        // Legacy keys unchanged.
        self::assertSame('/assembler-fixture', $document['endpoint']);
        self::assertSame(['plain'], $document['modes']);

        // Input present, with collection roles lit by the contributed block.
        $byName = [];
        foreach ($document['input']['fields'] as $field) {
            $byName[$field['name']] = $field;
        }
        self::assertSame('pagination', $byName['page']['role']);
        self::assertSame('sort', $byName['sort']['role']);
        self::assertSame('selection', $byName['query']['role'], 'GraphQL-profile query param is field selection');

        // Contributed block served verbatim; output resolved through the
        // contributor's response→resource link.
        self::assertSame(['sort' => ['fields' => ['id']]], $document['collection']);
        self::assertSame('assembler.fixture', $document['output']['type']);
    }

    #[Test]
    public function degrades_to_base_plus_input_without_contributors(): void
    {
        $assembler = DefaultRouteContractAssembler::forTesting($this->registryWithResource(), []);

        $document = $assembler->assemble(AssemblerPayloadFixture::class, AssemblerResponseFixture::class)->toDocument();

        self::assertArrayHasKey('input', $document);
        self::assertArrayNotHasKey('collection', $document);
        self::assertArrayNotHasKey('output', $document, 'core has no response→resource link of its own');

        // No roles without a collection block — except the GraphQL selection
        // marker, which is usage-driven by the route's render profile.
        foreach ($document['input']['fields'] as $field) {
            if ($field['name'] === 'query') {
                self::assertSame('selection', $field['role']);
                continue;
            }
            self::assertArrayNotHasKey('role', $field);
        }
    }

    #[Test]
    public function response_class_that_is_itself_a_registered_resource_serves_output(): void
    {
        $assembler = DefaultRouteContractAssembler::forTesting($this->registryWithResource(), []);

        $document = $assembler->assemble(AssemblerPayloadFixture::class, AssemblerResourceFixture::class)->toDocument();

        self::assertSame('assembler.fixture', $document['output']['type']);
    }

    #[Test]
    public function contributed_blocks_may_not_shadow_reserved_keys_and_first_wins(): void
    {
        $hostile = new class implements RouteContractBlockContributorInterface {
            public function contributeBlocks(string $payloadClass, ?string $responseClass): array
            {
                return [
                    'fields'     => ['hijacked' => true],
                    'input'      => ['hijacked' => true],
                    'collection' => ['hijacked' => true],
                ];
            }

            public function resolveResourceClass(?string $responseClass): ?string
            {
                return null;
            }
        };

        $assembler = DefaultRouteContractAssembler::forTesting(
            $this->registryWithResource(),
            [self::collectionContributor(), $hostile],
        );

        $document = $assembler->assemble(AssemblerPayloadFixture::class, null)->toDocument();

        self::assertArrayNotHasKey('hijacked', $document['fields'], 'legacy fields key survives untouched');
        self::assertArrayNotHasKey('hijacked', $document['input'], 'core-owned input block cannot be shadowed');
        self::assertSame(['sort' => ['fields' => ['id']]], $document['collection'], 'first contributor wins the key');
    }

    #[Test]
    public function assembly_is_cached_per_payload_response_pair(): void
    {
        $assembler = DefaultRouteContractAssembler::forTesting($this->registryWithResource(), []);

        $first  = $assembler->assemble(AssemblerPayloadFixture::class, null);
        $second = $assembler->assemble(AssemblerPayloadFixture::class, null);

        self::assertSame($first, $second);
    }
}

#[AsPublicPayload(
    path: '/assembler-fixture',
    methods: ['GET'],
    renderProfile: [RenderProfile::Json, RenderProfile::GraphQL],
)]
final class AssemblerPayloadFixture
{
    public ?int $page = null;
    public ?string $sort = null;
    public ?string $query = null;
}

/** Response fixture — linked to the resource only by the test contributor. */
final class AssemblerResponseFixture
{
}

/** Resource fixture — only its FQCN is needed for registry lookups. */
final class AssemblerResourceFixture
{
}
