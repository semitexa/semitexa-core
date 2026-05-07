<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Resource\Attribute\ResourceField;
use Semitexa\Core\Resource\Attribute\ResourceId;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\ResourceObjectInterface;

/**
 * Phase 6g test fixture: leaf Resource DTO of the
 * `customer.profile.preferences` nested vertical slice.
 */
#[ResourceObject(type: 'phase6g_preferences')]
final readonly class PreferencesResource implements ResourceObjectInterface
{
    public function __construct(
        #[ResourceId]
        public string $id,
        #[ResourceField]
        public string $theme,
    ) {
    }
}
