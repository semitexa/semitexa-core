<?php

declare(strict_types=1);

namespace Semitexa\Core\Session\Attribute;

use Attribute;

/**
 * Declares a class as a session payload bound to a storage segment.
 * Use with SessionInterface::getPayload() / setPayload() for typed, key-safe session access.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SessionSegment
{
    public function __construct(
        public string $segment,
    ) {
    }
}
