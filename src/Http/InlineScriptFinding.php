<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * One inline `<script>` that a strict `script-src` would refuse.
 *
 * A record rather than an array shape: the scanner, the command, the JSON
 * envelope and the tests all read the same four fields, and an array shape
 * that drifts hides the branch nobody took.
 */
final readonly class InlineScriptFinding
{
    public function __construct(
        /** Path relative to the project root. */
        public string $path,
        public int $line,
        /** The opening tag as written, trimmed for display. */
        public string $snippet,
        /**
         * Who has to fix it. The framework holds itself to the rule — a
         * package emitting a nonce-less inline script is a defect every
         * consumer inherits and none can fix. An application's own script is
         * its own decision: it is only broken if that project sets a policy,
         * and it is the project that knows.
         */
        public InlineScriptOwner $owner,
    ) {}

    /** @return array{path: string, line: int, snippet: string, owner: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'line' => $this->line,
            'snippet' => $this->snippet,
            'owner' => $this->owner->value,
        ];
    }
}
