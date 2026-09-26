<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

use Semitexa\Core\Contract\ContractFactoryInterface;

/**
 * Fixture: the shape the rule used to REQUIRE. Never load this file —
 * narrowing get(\BackedEnum) to a concrete enum is a fatal error in PHP.
 */
interface FactoryNarrowingChannel extends ContractFactoryInterface
{
    public function get(FixtureChannelKind $key): FixtureChannel;
}
