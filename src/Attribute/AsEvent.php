<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;

/**
 * Optional marker on event classes. Reserved for future attributes (e.g. log to journal, log success/failure).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AsEvent
{
    public function __construct()
    {
    }
}
