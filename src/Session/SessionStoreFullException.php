<?php

declare(strict_types=1);

namespace Semitexa\Core\Session;

/**
 * The session table had no room for a new session, even after expired rows
 * were reclaimed and the oldest-expiring ones evicted. Thrown rather than
 * swallowed: a session that was not stored must not look stored
 * (tk-swoole-session-table-exhaustion-dos).
 */
final class SessionStoreFullException extends \RuntimeException
{
}
