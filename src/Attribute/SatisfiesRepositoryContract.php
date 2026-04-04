<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Declares that this class implements a repository contract (the given interface).
 * Apply on the implementation class; the framework discovers these and registers
 * the contract in the DI container. When multiple modules provide an implementation
 * for the same interface, the one from the module that "extends" the other wins
 * (child module has higher priority).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SatisfiesRepositoryContract
{
    public function __construct(
        /** Contract (interface) that this class implements */
        public string $of
    ) {}
}
