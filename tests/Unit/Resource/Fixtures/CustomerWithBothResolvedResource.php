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
 * Phase 6e fixture: both relations resolver-backed.
 *   - profile      → RecordingProfileResolver (Phase 6d fixture)
 *   - addresses    → RecordingAddressesResolver (Phase 6e fixture)
 * Self-contained test double for the cross-profile renderer behaviour;
 * does not depend on any application module.
 */
#[ResourceObject(type: 'phase6e_customer')]
final readonly class CustomerWithBothResolvedResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/x/{id}/profile')]
        #[ResolveWith(RecordingProfileResolver::class)]
        public ?ResourceRef $profile,

        #[ResourceRefListAttr(target: AddressResource::class, expandable: true, include: 'addresses', href: '/x/{id}/addresses')]
        #[ResolveWith(RecordingAddressesResolver::class)]
        public ResourceRefList $addresses,
    ) {
    }
}
