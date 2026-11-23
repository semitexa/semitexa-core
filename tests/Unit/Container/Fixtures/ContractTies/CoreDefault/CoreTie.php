<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\CoreDefault;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\TieContract;

/** In no fixture module, so the registry counts it as Core's default. */
#[SatisfiesServiceContract(of: TieContract::class)]
final class CoreTie implements TieContract
{
}
