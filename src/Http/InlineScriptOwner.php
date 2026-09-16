<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

enum InlineScriptOwner: string
{
    /** Emitted by a semitexa/* package: blocking, because no consumer can fix it. */
    case Framework = 'framework';

    /** Emitted by the application's own modules: reported, not blocking. */
    case Application = 'application';
}
