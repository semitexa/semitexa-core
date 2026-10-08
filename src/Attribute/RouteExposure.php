<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

/**
 * How a payload route can be reached.
 *
 * Public: by its path, like any route. Hug: never by a path — only by NAME
 * through HUG (`POST /__semitexa_hug`), which admits it with the subscriber's
 * identity, tenant and validation, while its frames ride KISS. For framework
 * feeds that are not resources of their own (the collaborative form document):
 * KISS and HUG are the whole browser↔server transport, so such a feed must not
 * open a door of its own.
 */
enum RouteExposure: string
{
    case Public = 'public';
    case Hug = 'hug';
}
