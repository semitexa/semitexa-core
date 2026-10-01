<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

/**
 * The factory key InjectAsFactoryAtBootTest's contracts use.
 *
 * Its own file, not declared inside the test: an attribute argument naming an
 * enum case can only be read by code that can load the enum, and a class
 * declared in the middle of a test file is loadable only by running that file.
 * The project graph could not read the test's #[SatisfiesServiceContract]
 * attributes while it lived there.
 */
enum BootFactoryChannelKind: string
{
    case Mail = 'mail';
    case Sms = 'sms';
}
