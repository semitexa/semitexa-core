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
     * output instead. Only a real CALL counts — see {@see self::callsStamp()}.
     */
    private const STAMPS_ITSELF = 'CspNonce::stamp(';

    /* STAMPS_ITSELF is documentation now: callsStamp() matches on tokens, so
       the call may be spelled across lines or carry a comment inside it. */

    /**
     * An acknowledged exemption. Costs a written reason, which is the point:
     * the only honest case is markup no response of ours ever carries — a
     * static file served by something else — and that deserves a sentence
     * rather than a silent hole in the check.
     */
    public const EXEMPTION_MARKER = 'csp-nonce-exempt:';

    /** True when the file opts out with a written reason, or really calls the stamper. */
    public static function isExempt(string $contents): bool
    {
        return str_contains($contents, self::EXEMPTION_MARKER)
            || self::callsStamp($contents);
    }

    /**
     * True when this file really CALLS `CspNonce::stamp()`.
     *
     * Tokens, not str_contains: the words in a docblock or inside a string
     * used to exempt a file that calls nothing at all, which is a silent hole
     * in a check whose whole argument is that nothing else can see the defect.
     *
     * WHAT THIS STILL DOES NOT PROVE, said plainly rather than implied: that
     * the stamped document is the one carrying the tag below. Scoping the
     * exemption to the METHOD holding the call was written and measured, and
     * rejected: the dominant shape here is a private page() that builds the
     * markup and a handle() that stamps what it returns, and the strict rule
     * reported four correct handlers. Proving the emission reaches the stamp
     * is dataflow, which this is not. A file that stamps one document and
     * emits another bare is the gap that remains.
     */
    private static function callsStamp(string $contents): bool
    {
        // The prefilter is the CLASS NAME, not the whole call: PHP allows
        // whitespace and comments between `CspNonce`, `::` and `stamp`, and a
        // prefilter pinned to the compact spelling rejected a formatted call
        // before the tokens were ever consulted — so the file was scanned and
        // its correct inline script reported.
        if (!str_contains($contents, 'CspNonce')) {
            return false;
        }

        $tokens = @token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || !str_ends_with($token[1], 'CspNonce')) {
                continue;
            }

            $next = self::nextCodeToken($tokens, $i + 1, $count);
            if ($next >= $count || !is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_COLON) {
                continue;
            }

            $method = self::nextCodeToken($tokens, $next + 1, $count);
            if ($method < $count && is_array($tokens[$method])
                && $tokens[$method][0] === T_STRING && $tokens[$method][1] === 'stamp'
            ) {
                return true;
            }
        }

        return false;
    }

    /** The next token that is neither whitespace nor a comment. */
    private static function nextCodeToken(array $tokens, int $from, int $count): int
    {
        for ($j = $from; $j < $count; $j++) {
            if (!is_array($tokens[$j])) {
                return $j;
            }

            if (!in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return $count;
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
        if (stripos($contents, self::CLOSING_TAG) === false) {
            return [];
        }

        $findings = [];

        foreach (ScriptTag::sourceTags($contents) as $found) {
            $attributes = $found['attributes'];
            $offset = $found['start'];
            $tag = '<script' . $attributes . '>';

            if ($this->isSafe($attributes)) {
                continue;
            }

            $line = substr_count($contents, "\n", 0, $offset) + 1;

            if ($this->isProse($contents, $offset)) {
                continue;
            }

            $findings[] = new InlineScriptFinding(
                path: $relativePath,
                line: $line,
                snippet: $this->snippet($tag),
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
            || !ScriptTag::isExecutableInSource($attributes);
    }

    /**
     * True when the tag sits in a comment — a docblock explaining the very
     * rule this scanner enforces is the most likely `<script` in the tree, and
     * flagging prose would train people to ignore the whole check.
     */
    private function isProse(string $contents, int $offset): bool
    {
        // An HTML COMMENT is prose too, and the line-comment markers below do
        // not see it: `<!-- <script>…</script> -->` in a template emits
        // nothing, and reporting the note about a script is how a lint teaches
        // people to ignore it.
        if (self::isInsideHtmlComment($contents, $offset)) {
            return true;
        }

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

    /** True when `$offset` falls between an unclosed `<!--` and its `-->`. */
    private static function isInsideHtmlComment(string $contents, int $offset): bool
    {
        $open = strrpos(substr($contents, 0, $offset), '<!--');
        if ($open === false) {
            return false;
        }

        $close = strpos($contents, '-->', $open);

        return $close === false || $close > $offset;
    }

    private function snippet(string $tag): string
    {
        $tag = preg_replace('/\s+/', ' ', trim($tag)) ?? $tag;

        return mb_strlen($tag) > 120 ? mb_substr($tag, 0, 117) . '…' : $tag;
    }
}
