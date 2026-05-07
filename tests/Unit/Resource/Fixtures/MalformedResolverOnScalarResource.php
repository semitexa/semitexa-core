<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

#[ResourceObject(type: 'malformed_resolver_on_scalar')]
final readonly class MalformedResolverOnScalarResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceField]
        #[ResolveWith(StubRelationResolver::class)]
        public string $name,
    ) {
    }
}
