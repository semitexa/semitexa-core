<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * One Way Pattern — Phase 1: the `input` block of the route contract
 * document.
 *
 * WRAPS the field list that {@see PayloadMetadataReflector} already emits
 * (name/type/nullable/required[/filter]) — it does not re-reflect the
 * payload class. The one enrichment is `role`: on collection routes the
 * canonical collection params are recognized by name so a metadata-driven
 * client can wire them without per-route knowledge:
 *
 *   page / perPage / cursor → 'pagination'
 *   sort                    → 'sort'
 *   filter                  → 'filter'
 *
 * One Way Phase 2 adds the free-text search role: the field whose name the
 * route's contributed `collection.search.param` declares (from
 * `#[CollectionSearchable]`) is roled 'search'. Recognition is gated on the
 * declared param name — never on the bare name `q`, which legacy grid feed
 * payloads still carry un-roled, correctly. A field named `query` on a route
 * that declares the GraphQL render profile is GraphQL-subset FIELD
 * SELECTION, not search — it gets the distinct role 'selection' so the two
 * concerns stay machine-distinguishable (design §1.3 disambiguation).
 */
final readonly class ResolvedPayloadContract
{
    public const ROLE_PAGINATION = 'pagination';
    public const ROLE_SORT       = 'sort';
    public const ROLE_FILTER     = 'filter';
    public const ROLE_SELECTION  = 'selection';
    public const ROLE_SEARCH     = 'search';

    private const COLLECTION_ROLES = [
        'page'    => self::ROLE_PAGINATION,
        'perPage' => self::ROLE_PAGINATION,
        'cursor'  => self::ROLE_PAGINATION,
        'sort'    => self::ROLE_SORT,
        'filter'  => self::ROLE_FILTER,
    ];

    /** @param list<array<string, mixed>> $fields */
    public function __construct(
        public array $fields,
    ) {
    }

    /**
     * Build from the reflector's field list, enriching with roles.
     *
     * @param list<array<string, mixed>> $reflectedFields fields exactly as
     *        {@see PayloadMetadataReflector::describe()} emits them
     * @param bool $collectionContext whether the route carries a collection
     *        contract (a `collection` block was contributed) — the canonical
     *        collection-param roles light up only then, so a non-collection
     *        payload with an unrelated `page` field is not mis-roled
     * @param bool $graphqlSelection whether the route declares the GraphQL
     *        render profile — its `query` param is field selection
     * @param ?string $searchParam the declared free-text search param name
     *        (`collection.search.param`); null when the route declares no
     *        search — the role then never lights up
     */
    public static function fromReflectedFields(
        array $reflectedFields,
        bool $collectionContext,
        bool $graphqlSelection,
        ?string $searchParam = null,
    ): self {
        $fields = [];
        foreach ($reflectedFields as $field) {
            $name = $field['name'] ?? null;
            if (is_string($name)) {
                if ($collectionContext && $searchParam !== null && $name === $searchParam) {
                    $field['role'] = self::ROLE_SEARCH;
                } elseif ($collectionContext && isset(self::COLLECTION_ROLES[$name])) {
                    $field['role'] = self::COLLECTION_ROLES[$name];
                } elseif ($graphqlSelection && $name === 'query') {
                    $field['role'] = self::ROLE_SELECTION;
                }
            }
            $fields[] = $field;
        }

        return new self($fields);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['fields' => $this->fields];
    }
}
