<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRefList as ResourceRefListAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRefList;

#[ResourceObject(type: 'malformed-href-template')]
final readonly class MalformedHrefTemplateResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        // {tenantId} does not exist on this class — should be flagged by the validator.
        #[ResourceRefListAttr(target: AddressResource::class, href: '/customers/{tenantId}/addresses')]
        public ResourceRefList $addresses,
    ) {
    }
}
