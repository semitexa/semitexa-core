<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\ResourceIdentity;

/**
 * Phase 6e test fixture for the to-many resolver overlay path.
 * Records every `resolveBatch()` call and returns a deterministic list
 * of `AddressResource` items per parent identity.
 *
 * Phase 6f: each call entry now carries the full `parents` list (not
 * just the count) so multi-parent batching tests can assert on the
 * exact identities the pipeline batched together.
 */
#[AsService]
final class RecordingAddressesResolver implements RelationResolverInterface
{
    /** @var list<array{count: int, parents: list<ResourceIdentity>, ctx: RenderContext}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function resolveBatch(array $parents, RenderContext $ctx): array
    {
        self::$calls[] = ['count' => count($parents), 'parents' => $parents, 'ctx' => $ctx];

        $out = [];
        foreach ($parents as $parent) {
            $out[$parent->urn()] = [
                new AddressResource(id: $parent->id . '-a1', city: 'Kyiv',  line1: 'Khreshchatyk 1'),
                new AddressResource(id: $parent->id . '-a2', city: 'Lviv',  line1: 'Rynok 2'),
            ];
        }
        return $out;
    }
}
