<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

enum RouteType: string
{
    case HttpRequest = 'http-request';
    case Legacy = 'legacy';
}
