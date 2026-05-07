<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceListOf;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRefList;

#[ResourceObject(type: 'malformed-conflicting')]
final readonly class MalformedConflictingAttributesResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefListAttr(target: AddressResource::class)]
        #[ResourceListOf(target: AddressResource::class)]
        public ResourceRefList $addresses,
    ) {
    }
}
