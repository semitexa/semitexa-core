<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\Tenants;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\TieContract;

/** The Tenants fixture module's implementation of TieContract. */
#[SatisfiesServiceContract(of: TieContract::class)]
final class TenantsTie implements TieContract
{
}
