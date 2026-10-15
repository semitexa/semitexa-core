<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * The session store for one request whose real store could not be read.
 *
 * When Redis is down the session cannot be loaded, and failing the request
 * made every page of the site answer 500, anonymous visitors included. Such a
 * request now runs on this handler instead: it starts empty, keeps what the
 * request writes in memory, and persists nothing. That last part is the point
 * — writing this empty session back under the visitor's id once Redis returns
 * would sign them out for good. The visitor is treated as a guest for the
 * length of the outage and keeps their real session afterwards.
 */
final class TransientSessionHandler implements SessionHandlerInterface
{
    public function read(string $sessionId): array
    {
        return [];
    }

    public function write(string $sessionId, array $data, int $lifetimeSeconds = 3600): void
    {
    }

    public function destroy(string $sessionId): void
    {
    }
}
