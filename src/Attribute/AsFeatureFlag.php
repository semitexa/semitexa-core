<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Declares a feature flag. Put it on a class of the module that owns the flag
 * (by convention its FeatureFlags class); repeat it for several flags:
 *
 *     #[AsFeatureFlag('checkout.new-flow', description: 'The one-page checkout')]
 *     #[AsFeatureFlag('search.semantic', default: true)]
 *     final class FeatureFlags {}
 *
 * The declaration is the flag's default and its documentation; an operator
 * overrides it per site or per user (`feature:enable`). A flag nobody declared
 * is off.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class AsFeatureFlag
{
    public function __construct(
        public string $name,
        public bool $default = false,
        public string $description = '',
        /** On for this share (0-100) of users, the same users every time, unless overridden. */
        public ?int $percent = null,
    ) {}
}
