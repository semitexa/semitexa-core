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

/**
 * Phase 6d fixture: a Customer whose `profile` is resolver-backed
 * (`#[ResolveWith]`) and whose `addresses` is plain handler-eager
 * (no resolver). Used to exercise both expansion paths in one DTO.
 */
#[ResourceObject(type: 'phase6d_customer')]
final readonly class CustomerWithResolvedProfileResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/x/{id}/profile')]
        #[ResolveWith(RecordingProfileResolver::class)]
        public ?ResourceRef $profile,

        #[ResourceRefListAttr(target: AddressResource::class, expandable: true, include: 'addresses', href: '/x/{id}/addresses')]
        public ResourceRefList $addresses,
    ) {
    }
}
