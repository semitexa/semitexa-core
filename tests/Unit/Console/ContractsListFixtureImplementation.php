<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use Semitexa\Core\Attribute\SatisfiesServiceContract;

#[SatisfiesServiceContract(of: ContractsListFixtureContract::class)]
final class ContractsListFixtureImplementation implements ContractsListFixtureContract
{
}
