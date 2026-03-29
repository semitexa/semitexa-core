<?php

declare(strict_types=1);

namespace Semitexa\Core\Attributes;

use Attribute;

/**
 * Declares that this class implements a service contract (the given interface).
 * Apply on the implementation class; the framework discovers these and registers
 * the contract in the DI container. When multiple modules provide an implementation
 * for the same interface, the one from the module that "extends" the other wins
 * (child module has higher priority).
 *
 * For factory contracts, use `factoryKey` to specify the backed enum case
 * that selects this implementation.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class SatisfiesServiceContract
{
    public function __construct(
        /** Contract (interface) that this class implements */
        public string $of,
        /** Optional factory key (backed enum case) for multi-implementation factory contracts */
        public ?\BackedEnum $factoryKey = null,
    ) {}
}
