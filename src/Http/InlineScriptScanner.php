<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * Finds inline `<script>` blocks that a strict `script-src` would refuse.
 *
 * WHY THIS IS A LINT AND NOT A TEST. An inline script with no nonce does not
 * fail on the server. The response is a correct 200, the markup is exactly
 * what the author wrote, and the only witness is a browser console on a
 * project that happens to enforce a policy. That is how the SSR deferred
 * manifest shipped broken for every nonce-enforcing consumer: nothing on the
 * server side could tell. The emission is statically visible, so it is
 * checkable without running anything — which is the whole argument.
 *
 * WHAT COUNTS AS SAFE, and why each one is:
 *   - `src=`            — an external script, governed by the source list, not
 *                         by unsafe-inline. Still nonce-able, still allowed.
 *   - a non-executable  — `type="application/json"`, `application/ld+json` and
 *     `type`              friends are DATA. script-src does not govern them at
 *                         all, so they need no nonce and never will.
 *   - a nonce ASKED FOR — the attribute written out, or code that will write
 *                         one: `{$nonceAttr}`, `' . CspNonce::attribute() . '`,
 *                         `{{ csp_nonce_attr() }}`. Asked for in CODE, which
 *                         is not the same as the word appearing somewhere in
 *                         the tag — `data-nonce` and `id="nonce-bootstrap"`
 *                         are somebody else's attributes and are reported.
 *
 * WHAT IT CANNOT SEE, stated so nobody trusts it further than it goes: a tag
 * assembled from pieces far apart, a script written by a JS bundle at runtime,
 * and markup that arrives from outside the repository. It reads source files,
 * not responses.
 */
final class InlineScriptScanner
{
    /**
     * Spelled in two pieces on purpose: written whole, this file would contain
     * the closer, and the scanner would find its own regex literal below.
     */
    private const CLOSING_TAG = '</' . 'script';

    /**
     * A file that stamps its own finished document. Threading a nonce into a
     * nowdoc means either interpolating a page full of `$` or writing the
     * attribute by hand in a dozen places; `CspNonce::stamp()` does it to the
     * output instead, and a file that calls it says so in its own source.
     */
    private const STAMPS_ITSELF = 'CspNonce::stamp(';

    /**
     * An acknowledged exemption. Costs a written reason, which is the point:
     * the only honest case is markup no response of ours ever carries — a
     * static file served by something else — and that deserves a sentence
     * rather than a silent hole in the check.
     */
    public const EXEMPTION_MARKER = 'csp-nonce-exempt:';

    /** True when the file opts out or stamps its own output. */
    public static function isExempt(string $contents): bool
    {
        return str_contains($contents, self::EXEMPTION_MARKER)
            || str_contains($contents, self::STAMPS_ITSELF);
    }

    /**
     * Scan one file's contents.
     *
     * @return list<InlineScriptFinding>
     */
    public function scan(string $relativePath, string $contents, InlineScriptOwner $owner): array
    {
        if (self::isExempt($contents)) {
            return [];
        }

        // The opening tag must close on its own line, and the file must
        // contain a closer. Both keep PROSE out: a docblock or a help string
        // that mentions the tag never writes the closer, and a `>` reached by
        // crossing a newline is an arrow operator, not the end of a tag.
        if (stripos($contents, self::CLOSING_TAG) === false
            || !preg_match_all(ScriptTag::PATTERN, $contents, $matches, PREG_OFFSET_CAPTURE)
        ) {
            return [];
        }

        $findings = [];

        foreach ($matches[0] as $index => [$tag, $offset]) {
            $attributes = (string) $matches[1][$index][0];

            if ($this->isSafe($attributes)) {
                continue;
            }

            $line = substr_count($contents, "\n", 0, (int) $offset) + 1;

            if ($this->isProse($contents, (int) $offset)) {
                continue;
            }

            $findings[] = new InlineScriptFinding(
                path: $relativePath,
                line: $line,
                snippet: $this->snippet((string) $tag),
                owner: $owner,
            );
        }

        return $findings;
    }

    /**
     * A tag is safe when it already asks for a nonce, when it loads its code
     * from a URL the source list allows, or when it is not executable at all.
     * The classification itself lives in {@see ScriptTag} — the stamper reads
     * the same rule, and two copies of it would drift apart silently.
     */
    private function isSafe(string $attributes): bool
    {
        return ScriptTag::asksForNonce($attributes)
            || ScriptTag::hasSrc($attributes)
            || !ScriptTag::isExecutable($attributes);
    }

    /**
     * True when the tag sits in a comment — a docblock explaining the very
     * rule this scanner enforces is the most likely `<script` in the tree, and
     * flagging prose would train people to ignore the whole check.
     */
    private function isProse(string $contents, int $offset): bool
    {
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $before = substr($contents, $lineStart, $offset - $lineStart);
        $trimmed = ltrim($before);

        foreach (['*', '//', '/*', '{#', '#'] as $marker) {
            if (str_starts_with($trimmed, $marker)) {
                return true;
            }
        }

        // A comment that opens partway along the line — `echo $x; // emits a
        // <script> for the widget`. The space after the marker is what keeps
        // `https://…` and a `#fragment` out of this branch.
        return preg_match('~(^|\s)(//|\#|\{\#)\s~', $before) === 1;
    }

    private function snippet(string $tag): string
    {
        $tag = preg_replace('/\s+/', ' ', trim($tag)) ?? $tag;

        return mb_strlen($tag) > 120 ? mb_substr($tag, 0, 117) . '…' : $tag;
    }
}
