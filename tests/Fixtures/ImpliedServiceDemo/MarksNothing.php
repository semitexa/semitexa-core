<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Fixtures\ImpliedServiceDemo;

/** An attribute that says nothing to the container. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MarksNothing
{
}
