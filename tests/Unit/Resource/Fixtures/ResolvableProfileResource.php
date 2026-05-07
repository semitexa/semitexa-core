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
 * Fixture: a Customer-like Resource where `profile` is resolver-backed
 * (`#[ResolveWith]`) and `addresses` is intentionally NOT resolver-backed.
 * Used by Phase 6c tests that exercise the resolver-vs-handler-provided
 * dual-mechanism rule.
 */
#[ResourceObject(type: 'resolvable_with_profile')]
final readonly class ResolvableProfileResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,

        #[ResourceRefAttr(target: ProfileResource::class, expandable: true, include: 'profile', href: '/x/{id}/profile')]
        #[ResolveWith(StubRelationResolver::class)]
        public ?ResourceRef $profile,
    ) {
    }
}
