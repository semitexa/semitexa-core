<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Marks a {@see \Semitexa\Core\Discovery\DiscoveryContributor} so boot-time
 * discovery finds it.
 *
 * A package teaches discovery about its own attributes by shipping a contributor
 * and tagging it with this — no registration call, no wiring in core, and no
 * mention of the package anywhere upstream.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsDiscoveryContributor
{
    public function __construct(
        /**
         * Run order, highest first. Contributions are usually independent, but
         * where one builds on another's result the dependent side must declare a
         * lower priority — registering a handler for a slot that does not exist
         * yet fails in a way that is tedious to trace back to ordering.
         *
         * Ties are broken by class name so a boot is reproducible rather than
         * dependent on filesystem scan order.
         */
        public int $priority = 0,
        public ?string $doc = null,
    ) {
    }
}
