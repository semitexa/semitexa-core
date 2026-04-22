<?php

declare(strict_types=1);

namespace Semitexa\Core\Registry;

/**
 * Single source of truth for registry directory layout and namespace.
 * Every path referencing src/registry/* must read from here — never a literal.
 */
final class CanonicalRegistryPaths
{
    public const REGISTRY_ROOT = 'src/registry';
    public const REGISTRY_CONTRACTS = 'src/registry/Contracts';
    public const REGISTRY_PAYLOADS = 'src/registry/Payloads';
    public const REGISTRY_RESOURCES = 'src/registry/Resources';
    public const REGISTRY_NAMESPACE = 'App\\Registry';
}
