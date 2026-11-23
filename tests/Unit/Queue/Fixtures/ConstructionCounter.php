<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Queue\Fixtures;

/**
 * A class a queue message can name as its request, response or event: it
 * counts how often it is built, so a test can tell that the worker built
 * nothing of a message it refused.
 */
final class ConstructionCounter
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }
}
