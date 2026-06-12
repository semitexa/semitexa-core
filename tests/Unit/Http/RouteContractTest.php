<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\ResolvedPayloadContract;
use Semitexa\Core\Http\ResolvedResourceContract;
use Semitexa\Core\Http\RouteContract;
use Semitexa\Core\Resource\Metadata\ResourceFieldKind;
use Semitexa\Core\Resource\Metadata\ResourceFieldMetadata;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceObjectMetadata;

/**
 * One Way Pattern — Phase 1: the contract DTOs and the joined document.
 *
 * The DTOs WRAP existing truth: ResolvedPayloadContract carries the
 * reflector's field list verbatim plus the `role` enrichment;
 * ResolvedResourceContract serializes ResourceObjectMetadata; RouteContract
 * appends the new keys additively to the legacy base document.
 */
final class RouteContractTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function collectionFields(): array
    {
        return [
            ['name' => 'q',       'type' => 'string', 'nullable' => true, 'required' => false],
            ['name' => 'query',   'type' => 'string', 'nullable' => true, 'required' => false],
            ['name' => 'page',    'type' => 'int',    'nullable' => true, 'required' => false],
            ['name' => 'perPage', 'type' => 'int',    'nullable' => true, 'required' => false],
            ['name' => 'cursor',  'type' => 'string', 'nullable' => true, 'required' => false],
            ['name' => 'sort',    'type' => 'string', 'nullable' => true, 'required' => false],
            ['name' => 'filter',  'type' => 'string', 'nullable' => true, 'required' => false],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function byName(ResolvedPayloadContract $contract): array
    {
        $byName = [];
        foreach ($contract->fields as $field) {
            $byName[$field['name']] = $field;
        }

        return $byName;
    }

    #[Test]
    public function collection_context_roles_canonical_params_only(): void
    {
        $contract = ResolvedPayloadContract::fromReflectedFields(
            self::collectionFields(),
            collectionContext: true,
            graphqlSelection: false,
        );

        $byName = self::byName($contract);
        self::assertSame('pagination', $byName['page']['role']);
        self::assertSame('pagination', $byName['perPage']['role']);
        self::assertSame('pagination', $byName['cursor']['role']);
        self::assertSame('sort', $byName['sort']['role']);
        self::assertSame('filter', $byName['filter']['role']);
        // `q` (search) is Phase 2 — no role yet; `query` only roles on
        // GraphQL-profile routes.
        self::assertArrayNotHasKey('role', $byName['q']);
        self::assertArrayNotHasKey('role', $byName['query']);
    }

    #[Test]
    public function no_collection_context_means_no_collection_roles(): void
    {
        $contract = ResolvedPayloadContract::fromReflectedFields(
            self::collectionFields(),
            collectionContext: false,
            graphqlSelection: false,
        );

        foreach ($contract->fields as $field) {
            self::assertArrayNotHasKey(
                'role',
                $field,
                "field {$field['name']} must stay un-roled outside a collection contract",
            );
        }
    }

    #[Test]
    public function graphql_profile_marks_query_as_selection(): void
    {
        $contract = ResolvedPayloadContract::fromReflectedFields(
            self::collectionFields(),
            collectionContext: false,
            graphqlSelection: true,
        );

        $byName = self::byName($contract);
        self::assertSame('selection', $byName['query']['role']);
        self::assertArrayNotHasKey('role', $byName['q']);
    }

    #[Test]
    public function resource_contract_serializes_metadata_fields(): void
    {
        $metadata = new ResourceObjectMetadata(
            class: RouteContractTargetFixture::class,
            type: 'fixture.article',
            idField: 'id',
            fields: [
                'id' => new ResourceFieldMetadata(
                    name: 'id',
                    kind: ResourceFieldKind::Scalar,
                    nullable: false,
                ),
                'title' => new ResourceFieldMetadata(
                    name: 'title',
                    kind: ResourceFieldKind::Scalar,
                    nullable: false,
                    description: 'Article title.',
                ),
                'category' => new ResourceFieldMetadata(
                    name: 'category',
                    kind: ResourceFieldKind::RefOne,
                    nullable: true,
                    target: RouteContractTargetFixture::class,
                    hrefTemplate: '/articles/{id}/category',
                ),
                'unknownRef' => new ResourceFieldMetadata(
                    name: 'unknownRef',
                    kind: ResourceFieldKind::RefMany,
                    nullable: false,
                    target: 'Acme\\External\\WidgetResource',
                    list: true,
                ),
            ],
        );

        $registry = ResourceMetadataRegistry::forTesting(new ResourceMetadataExtractor());
        $registry->register(new ResourceObjectMetadata(
            class: RouteContractTargetFixture::class,
            type: 'fixture.category',
            idField: 'id',
            fields: [],
        ));

        $contract = ResolvedResourceContract::fromMetadata($metadata, $registry);
        $array = $contract->toArray();

        self::assertSame('fixture.article', $array['type']);
        self::assertSame('id', $array['idField']);

        $byName = [];
        foreach ($array['fields'] as $field) {
            $byName[$field['name']] = $field;
        }

        self::assertSame(
            ['name' => 'id', 'kind' => 'scalar', 'nullable' => false, 'list' => false],
            $byName['id'],
        );
        self::assertSame('Article title.', $byName['title']['description']);
        // A registered target serves its registry type handle, never a FQCN.
        self::assertSame('fixture.category', $byName['category']['target']);
        self::assertSame('/articles/{id}/category', $byName['category']['href']);
        // An unregistered target degrades to the class basename.
        self::assertSame('WidgetResource', $byName['unknownRef']['target']);
        self::assertTrue($byName['unknownRef']['list']);
        self::assertArrayNotHasKey('href', $byName['unknownRef']);
    }

    #[Test]
    public function document_is_base_plus_additive_keys_in_order(): void
    {
        $base = [
            'endpoint'  => '/things',
            'methods'   => ['GET'],
            'access'    => 'public',
            'transport' => 'http',
            'modes'     => ['plain'],
            'fields'    => [],
        ];
        $input  = new ResolvedPayloadContract([]);
        $output = new ResolvedResourceContract('fixture.thing', 'id', []);

        $contract = new RouteContract($base, $input, $output, ['collection' => ['sort' => ['fields' => ['id']]]]);
        $document = $contract->toDocument();

        // Legacy keys are byte-identical and come first; new keys are appended.
        self::assertSame(
            ['endpoint', 'methods', 'access', 'transport', 'modes', 'fields', 'input', 'collection', 'output'],
            array_keys($document),
        );
        self::assertSame($base, array_intersect_key($document, $base));
    }

    #[Test]
    public function degraded_document_is_base_plus_input_only(): void
    {
        $base = ['endpoint' => '/page', 'methods' => ['GET'], 'access' => 'public', 'transport' => 'http', 'modes' => ['plain'], 'fields' => []];

        $document = (new RouteContract($base, new ResolvedPayloadContract([]), null, []))->toDocument();

        self::assertSame(['endpoint', 'methods', 'access', 'transport', 'modes', 'fields', 'input'], array_keys($document));
        self::assertArrayNotHasKey('collection', $document);
        self::assertArrayNotHasKey('output', $document);
    }

    #[Test]
    public function etag_is_stable_for_equal_documents_and_differs_when_content_changes(): void
    {
        $base = ['endpoint' => '/a', 'methods' => ['GET'], 'access' => 'public', 'transport' => 'http', 'modes' => ['plain'], 'fields' => []];

        $a1 = new RouteContract($base, new ResolvedPayloadContract([]), null, []);
        $a2 = new RouteContract($base, new ResolvedPayloadContract([]), null, []);
        $b  = new RouteContract(['endpoint' => '/b'] + $base, new ResolvedPayloadContract([]), null, []);

        self::assertSame($a1->etag(), $a2->etag());
        self::assertNotSame($a1->etag(), $b->etag());
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $a1->etag());
    }
}

/** Target fixture — only its FQCN is needed for registry lookups. */
final class RouteContractTargetFixture
{
}
