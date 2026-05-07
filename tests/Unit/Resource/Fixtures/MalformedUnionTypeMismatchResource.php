<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceUnion;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

#[ResourceObject(type: 'malformed-union-type')]
final readonly class MalformedUnionTypeMismatchResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        // list: true requires ResourceRefList, but field is typed ResourceRef.
        #[ResourceUnion(targets: [UserResource::class, BotResource::class], list: true)]
        public ResourceRef $authors,
    ) {
    }
}
