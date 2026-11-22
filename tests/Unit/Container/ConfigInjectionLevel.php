<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Container;

/** An int-backed enum for ConfigInjectionTest: its variable arrives as a string. */
enum ConfigInjectionLevel: int
{
    case Low = 1;
    case High = 2;
    case Negative = -3;
}
