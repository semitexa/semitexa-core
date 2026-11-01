<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

interface FactoryWithoutGet
{
    public function getDefault(): FixtureChannel;
}
