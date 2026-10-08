<?php

declare(strict_types=1);

namespace Semitexa\Core\Auth;

/**
 * The payload an identity check is run for when there is no route payload:
 * "who is this request's visitor", nothing else. It declares no access
 * attributes, so an auth handler that keys on them (a machine-auth route)
 * passes it by, and a session handler resolves the visitor as for a public
 * page. See {@see \Semitexa\Core\Pipeline\RouteExecutor::establishVisitor()}.
 */
final class VisitorProbe
{
}
