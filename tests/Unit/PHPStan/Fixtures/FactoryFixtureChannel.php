<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures;

interface FactoryFixtureChannel
{
    public function getDefault(): FixtureChannel;

    public function get(FixtureChannelKind $key): FixtureChannel;

    public function keys(): array;
}
