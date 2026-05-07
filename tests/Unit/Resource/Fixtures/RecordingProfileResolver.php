<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\ResourceIdentity;

/**
 * Phase 6d test fixture: records each resolveBatch() call and returns
 * a deterministic to-one mapping per parent. Used by Phase6dPipelineTest
 * to verify call counts, parent-list shape, and overlay placement.
 */
#[AsService]
final class RecordingProfileResolver implements RelationResolverInterface
{
    /** @var list<array{parents: list<ResourceIdentity>, ctx: RenderContext}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function resolveBatch(array $parents, RenderContext $ctx): array
    {
        self::$calls[] = ['parents' => $parents, 'ctx' => $ctx];
        $out = [];
        foreach ($parents as $parent) {
            $out[$parent->urn()] = new ProfileResource(
                id:  $parent->id . '-profile',
                bio: 'fixture bio for ' . $parent->id,
            );
        }
        return $out;
    }
}
