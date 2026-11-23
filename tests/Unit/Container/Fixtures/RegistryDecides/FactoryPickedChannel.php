<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides;

interface FactoryPickedChannel
{
    public function getDefault(): PickedChannel;

    public function get(PickedChannelKind $key): PickedChannel;

    /** @return list<PickedChannelKind> */
    public function keys(): array;
}
