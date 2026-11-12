<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery\Fixture;

use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Auth\PayloadAccessType;

/** A route attribute that refuses an argument the way AsCollectionFeed refuses access: Public with no reason. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RefusingRouteAttribute extends AbstractPayloadRoute
{
    public function __construct()
    {
        throw new \InvalidArgumentException('a public route needs a reason');
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Public;
    }
}
