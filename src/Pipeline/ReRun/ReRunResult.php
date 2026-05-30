<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline\ReRun;

use Semitexa\Core\HttpResponse;

/**
 * Outcome of a single re-run (Track R · R2, Shape 1 "re-run self-authorization").
 *
 * A re-run of a frozen authorized request resolves to exactly one of two states:
 *
 *  - a freshly-resolved {@see HttpResponse} frame (data re-queried under the
 *    recipient's *current* authorization), or
 *  - a TERMINATE signal — the subject lost access on this tick (logout / identity
 *    change / permission revoke), so the stream must be closed and NO data frame
 *    is produced.
 *
 * This is the small result VO the design (phase2 §B.3 step 4) calls for instead of
 * a raw `HttpResponse|TERMINATE` union: it keeps the terminate reason for the close
 * frame, and forces every caller to branch on {@see isTerminated()} before reading a
 * frame, so a de-authorized re-run can never be mistaken for an empty data frame.
 */
final class ReRunResult
{
    private function __construct(
        public readonly bool $terminated,
        public readonly ?HttpResponse $frame,
        public readonly ?string $reason,
    ) {}

    /**
     * A successful re-run: the freshly-resolved frame to write to the stream.
     */
    public static function frame(HttpResponse $frame): self
    {
        return new self(false, $frame, null);
    }

    /**
     * The subject is no longer authorized on this tick — terminate the stream.
     * No data frame is carried; the loop emits a close frame and ends the stream.
     */
    public static function terminate(string $reason): self
    {
        return new self(true, null, $reason);
    }

    public function isTerminated(): bool
    {
        return $this->terminated;
    }

    /**
     * The freshly-resolved frame. Only meaningful when {@see isTerminated()} is
     * false; a terminated result carries no frame.
     */
    public function getFrame(): ?HttpResponse
    {
        return $this->frame;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
