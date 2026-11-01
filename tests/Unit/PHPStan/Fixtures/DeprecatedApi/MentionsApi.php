<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApi;

/**
 * Not marked: the line below only mentions /** @api in its prose.
 */
final class MentionsApi
{
    /** @deprecated whenever */
    public function anything(): int
    {
        return 0;
    }
}
