<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container\Fixtures\RegistryDecides;

enum PickedChannelKind: string
{
    case Mail = 'mail';
    case Sms = 'sms';
}
