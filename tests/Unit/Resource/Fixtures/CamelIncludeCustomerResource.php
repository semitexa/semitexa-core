<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

/**
 * Fixture: a resolver-backed relation whose explicit include name
 * carries uppercase letters (`userProfile`).
 */
#[ResourceObject(type: 'camel_include_customer')]
final readonly class CamelIncludeCustomerResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'userProfile', href: '/c/{id}/profile')]
        #[ResolveWith(RecordingProfileResolver::class)]
        public ?ResourceRef $userProfile,
    ) {
    }
}
