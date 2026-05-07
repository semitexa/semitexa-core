<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

#[ResourceObject(type: 'malformed_resolver_wrong_interface')]
final readonly class MalformedResolverWrongInterfaceResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        // AddressResource exists, but is a Resource DTO — it does NOT
        // implement RelationResolverInterface.
        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/x/{id}/profile')]
        #[ResolveWith(AddressResource::class)]
        public ?ResourceRef $profile,
    ) {
    }
}
