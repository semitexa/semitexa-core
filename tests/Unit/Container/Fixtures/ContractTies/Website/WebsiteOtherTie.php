<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\Website;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Tests\Unit\Container\Fixtures\ContractTies\TieContract;

/** A second implementation of TieContract in the Website fixture module. */
#[SatisfiesServiceContract(of: TieContract::class)]
final class WebsiteOtherTie implements TieContract
{
}
