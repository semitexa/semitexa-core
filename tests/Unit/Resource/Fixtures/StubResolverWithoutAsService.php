<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;

/**
 * Implements the resolver contract but is missing the `#[AsService]`
 * marker. Used only to exercise the validator's "resolvers must be
 * registered as services" rule.
 */
final class StubResolverWithoutAsService implements RelationResolverInterface
{
    public function resolveBatch(array $parents, RenderContext $ctx): array
    {
        return [];
    }
}
