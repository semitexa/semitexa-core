<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * The one door to a page's live timeline (ep-platform-live-state ·
 * tk-ls-timeline): what a page's components sent (HUG) and what its stream
 * wrote back (KISS), in order, keyed by the page's KISS session id — the one
 * thing a page's requests and its stream share.
 *
 * A no-op until a development tool installs a sink at worker boot; in
 * production nothing does, and a record costs one null check.
 */
final class PageTimeline
{
    /** Worker-lifetime, set at boot: not request state. */
    private static ?PageTimelineSinkInterface $sink = null;

    public static function use(?PageTimelineSinkInterface $sink): void
    {
        self::$sink = $sink;
    }

    public static function isOn(): bool
    {
        return self::$sink !== null;
    }

    /**
     * The session id under which {@see broadcast()} hands an event to the sink:
     * not a page — no page id contains it — but the whole server.
     */
    public const SERVER = '*';

    /**
     * An event that belongs to no one page — a scope invalidation the server
     * published, which every page watching that scope then re-runs from. The
     * sink receives it under {@see SERVER}.
     *
     * @param string $type signal
     * @param array<string, scalar|null|array<array-key, scalar|null>> $fields
     */
    public static function broadcast(string $type, array $fields = []): void
    {
        self::record(self::SERVER, $type, $fields);
    }

    /**
     * @param string $type event | frame | subscribe | unsubscribe | rerun | replay | reset | deferred
     * @param array<string, scalar|null|array<array-key, scalar|null>> $fields
     */
    public static function record(string $sessionId, string $type, array $fields = []): void
    {
        if (self::$sink === null || $sessionId === '') {
            return;
        }
        try {
            self::$sink->record($sessionId, ['t' => round(microtime(true) * 1000, 1), 'type' => $type] + $fields);
        } catch (\Throwable) {
            // A development aid must never break the page it observes.
        }
    }
}
