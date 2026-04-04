<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Injects a scalar configuration value into a protected property.
 * This is the only mechanism for scalar values on container-managed framework objects.
 *
 * - If `env` is set: reads from environment variable, falls back to `default`.
 * - If `env` is null: uses `default` directly (compile-time constant).
 * - Property must be `protected` and have a scalar type (int, float, string, bool) or a backed enum.
 * - Arrays are forbidden. Use a typed DTO or collection object instead.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Config
{
    public function __construct(
        public readonly ?string $env = null,
        public readonly int|float|string|bool|null $default = null,
    ) {}
}
