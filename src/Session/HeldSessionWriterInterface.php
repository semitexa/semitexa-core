<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * A session store that can write an id only while it still holds a session
 * under it, as one step.
 *
 * Session::save() needs this for an id a browser holds: between the request's
 * read and its save, a sign-in or a sign-out in another request may have
 * retired that id, and a blind write would put the old session back. A handler
 * without this interface gets the same check as a read followed by a write,
 * with the gap between the two.
 */
interface HeldSessionWriterInterface extends SessionHandlerInterface
{
    /**
     * Writes $data under $sessionId when the store holds data under it that is
     * not $unless; otherwise writes nothing. "Holds" is what read() means by
     * it: an absent or expired id is not held.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $unless stored data that counts as "not held" (the retired-id marker)
     * @return bool whether it wrote
     */
    public function writeIfHeld(string $sessionId, array $data, int $lifetimeSeconds, array $unless): bool;
}
