<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;

/**
 * Test fixture: resolves `UnionCommentResource::$author` to a different
 * union target per comment id — `u*` → user, `b*` → bot, `k*` → keyed bot.
 */
#[AsService]
final class UnionAuthorResolver implements RelationResolverInterface
{
    public function resolveBatch(array $parents, RenderContext $ctx): array
    {
        $out = [];
        foreach ($parents as $parent) {
            $out[$parent->urn()] = match ($parent->id[0]) {
                'u'     => new UnionUserResource(id: $parent->id . '-author'),
                'b'     => new UnionBotResource(id: $parent->id . '-author'),
                default => new UnionKeyedBotResource(botKey: $parent->id . '-author'),
            };
        }
        return $out;
    }
}
