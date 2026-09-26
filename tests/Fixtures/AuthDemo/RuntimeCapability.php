<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\AuthDemo;

use Semitexa\Authorization\Domain\Contract\CapabilityInterface;

enum RuntimeCapability: string implements CapabilityInterface
{
    case Ping = 'core-fixture.runtime.ping';
}
