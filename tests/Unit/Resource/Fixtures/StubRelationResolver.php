<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;

#[AsService]
final class StubRelationResolver implements RelationResolverInterface
{
    public function resolveBatch(array $parents, RenderContext $ctx): array
    {
        return [];
    }
}
