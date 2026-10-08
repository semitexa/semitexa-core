<?php

declare(strict_types=1);

namespace Semitexa\Core\Server;

/**
 * Where a page's live timeline is written: the UI events its components
 * sent, the frames its KISS stream wrote, its subscriptions and re-runs.
 * Provided by a development tool (the Observatory); absent in production.
 *
 * A session id of {@see PageTimeline::SERVER} is not a page: it carries what
 * {@see PageTimeline::broadcast()} reports for the whole server.
 */
interface PageTimelineSinkInterface
{
    /** @param array<string, scalar|null|array<array-key, scalar|null>> $entry */
    public function record(string $sessionId, array $entry): void;
}
