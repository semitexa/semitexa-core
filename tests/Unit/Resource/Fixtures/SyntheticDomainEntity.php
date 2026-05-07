<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

/**
 * A synthetic domain-entity stand-in. Only used by the validator test that
 * checks that ResourceDTOs may not declare static `from*(DomainEntity)` factories.
 */
final class SyntheticDomainEntity
{
    public function __construct(public readonly string $id)
    {
    }
}
