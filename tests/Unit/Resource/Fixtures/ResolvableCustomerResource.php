<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;
use Semitexa\Core\Resource\ResourceRefList;

#[ResourceObject(type: 'resolvable_customer')]
final readonly class ResolvableCustomerResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/customers/{id}/profile')]
        #[ResolveWith(StubRelationResolver::class)]
        public ?ResourceRef $profile,

        #[ResourceRefListAttr(target: AddressResource::class, expandable: true, include: 'addresses', href: '/customers/{id}/addresses')]
        public ResourceRefList $addresses,
    ) {
    }
}
