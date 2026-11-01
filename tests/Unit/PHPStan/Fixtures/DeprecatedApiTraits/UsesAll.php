<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\PHPStan\Fixtures\DeprecatedApiTraits;

final class UsesAll
{
    use ApiHelper;
    use PromotesApi;
    use Unmarked;
}
