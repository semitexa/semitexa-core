<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\Website;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\TieContract;

/** The Website fixture module's implementation of TieContract. */
#[SatisfiesServiceContract(of: TieContract::class)]
final class WebsiteTie implements TieContract
{
}
