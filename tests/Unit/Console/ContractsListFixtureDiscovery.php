<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\ClassDiscovery;

/** A classmap of the given contract implementations and nothing else. */
final class ContractsListFixtureDiscovery extends ClassDiscovery
{
    /** @param list<class-string> $implementations */
    public function __construct(private readonly array $implementations)
    {
    }

    public function initialize(): void
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $attributeClass === SatisfiesServiceContract::class ? $this->implementations : [];
    }
}
