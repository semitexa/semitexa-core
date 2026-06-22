<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\ResolvedPayloadContract;

/**
 * One Way: the `search` input role — gated on the DECLARED
 * search param of a collection route, never on the bare field name,
 * so legacy grid-feed payloads carrying un-roled `q` fields stay
 * un-roled.
 */
final class RouteContractSearchRoleTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function fields(): array
    {
        return [
            ['name' => 'q',    'type' => 'string', 'nullable' => false, 'required' => true, 'filter' => false],
            ['name' => 'page', 'type' => 'string', 'nullable' => false, 'required' => true, 'filter' => false],
        ];
    }

    #[Test]
    public function declared_search_param_gets_the_search_role(): void
    {
        $contract = ResolvedPayloadContract::fromReflectedFields(
            self::fields(),
            collectionContext: true,
            graphqlSelection: false,
            searchParam: 'q',
        );

        self::assertSame(ResolvedPayloadContract::ROLE_SEARCH, $contract->fields[0]['role']);
        self::assertSame(ResolvedPayloadContract::ROLE_PAGINATION, $contract->fields[1]['role']);
    }

    #[Test]
    public function bare_q_field_without_a_declaration_stays_un_roled(): void
    {
        $contract = ResolvedPayloadContract::fromReflectedFields(
            self::fields(),
            collectionContext: true,
            graphqlSelection: false,
        );

        self::assertArrayNotHasKey('role', $contract->fields[0]);
    }

    #[Test]
    public function search_role_requires_collection_context(): void
    {
        $contract = ResolvedPayloadContract::fromReflectedFields(
            self::fields(),
            collectionContext: false,
            graphqlSelection: false,
            searchParam: 'q',
        );

        self::assertArrayNotHasKey('role', $contract->fields[0]);
        self::assertArrayNotHasKey('role', $contract->fields[1]);
    }

    #[Test]
    public function a_custom_search_param_name_is_honored(): void
    {
        $fields = [
            ['name' => 'term', 'type' => 'string', 'nullable' => true, 'required' => false, 'filter' => false],
            ['name' => 'q',    'type' => 'string', 'nullable' => true, 'required' => false, 'filter' => false],
        ];
        $contract = ResolvedPayloadContract::fromReflectedFields(
            $fields,
            collectionContext: true,
            graphqlSelection: false,
            searchParam: 'term',
        );

        self::assertSame(ResolvedPayloadContract::ROLE_SEARCH, $contract->fields[0]['role']);
        self::assertArrayNotHasKey('role', $contract->fields[1]);
    }
}
