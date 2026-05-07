<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResolveWith;
use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Attribute\ResourceRef as ResourceRefAttr;
use Semitexa\Core\Resource\ResourceObjectInterface;
use Semitexa\Core\Resource\ResourceRef;

#[ResourceObject(type: 'profile')]
final readonly class ProfileResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,
        #[ResourceField]
        public string $bio,

        // Phase 6g: optional resolver-backed nested relation. Default
        // null keeps Phase 6d/6e/6f resolvers and tests that build
        // `ProfileResource(id, bio)` working unchanged. Tests that
        // exercise nested expansion register
        // `RecordingPreferencesResolver` and request
        // `?include=profile.preferences`.
        #[ResourceRefAttr(
            target: PreferencesResource::class,
            expandable: true,
            include: 'preferences',
            href: '/profiles/{id}/preferences',
        )]
        #[ResolveWith(RecordingPreferencesResolver::class)]
        public ?ResourceRef $preferences = null,
    ) {
    }
}
