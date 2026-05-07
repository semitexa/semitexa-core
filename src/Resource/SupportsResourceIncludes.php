<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

/**
 * Marker for payloads that support the `?include=…` projection hint.
 *
 * Endpoints that do not implement this interface ignore `?include=` entirely
 * (no error, just absent). HTML routes must explicitly opt in — see
 * decision D4 in var/docs/resource-dto-relation-contract-design.md.
 */
interface SupportsResourceIncludes
{
    public function includes(): IncludeSet;
}
