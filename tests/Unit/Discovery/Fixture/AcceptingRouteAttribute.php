<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery\Fixture;

use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Auth\PayloadAccessType;

/** A route attribute that takes what it is given: the control next to RefusingRouteAttribute. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AcceptingRouteAttribute extends AbstractPayloadRoute
{
    public function __construct(string $path)
    {
        parent::__construct(path: $path, methods: ['GET']);
    }

    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Protected;
    }
}
