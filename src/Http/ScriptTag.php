<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * What an opening `<script …>` tag is, as far as a CSP is concerned.
 *
 * One rule, read by both sides of this feature: the scanner that reports a tag
 * a policy would refuse, and the stamper that puts a nonce on one. Two copies
 * of "is this executable" would drift, and the drift would be invisible —
 * a stamper that misses what the scanner flags leaves a build red with nothing
 * to fix, and the reverse ships the defect the scanner exists to catch.
 *
 * The one place the two sides legitimately differ is what they are reading.
 * The scanner reads SOURCE, where a nonce is usually still a call or a
 * variable; the stamper reads a FINISHED DOCUMENT, where only the attribute
 * itself exists. That difference is two named methods and two named patterns,
 * not two copies of the rule.
 */
final class ScriptTag
{
    /**
     * Source scanning. Bounded to one line: a `>` reached by crossing a
     * newline is an arrow operator, not the end of a tag.
     */
    public const PATTERN = '/<script\b([^>\n]*)>/i';

    /**
     * Finished markup, where that ambiguity does not exist and an author may
     * legitimately wrap a long opening tag:
     *
     *     <script
     *       type="module"
     *       defer>
     *
     * Scanning a document with {@see self::PATTERN} skips such a tag entirely,
     * so it is served without a nonce and a nonce policy refuses it — silently,
     * in the browser, which is the exact failure this whole class exists to
     * prevent.
     */
    public const DOCUMENT_PATTERN = '/<script\b([^>]*)>/i';

    /**
     * Types the browser EXECUTES, and which script-src therefore governs.
     *
     * The empty string is the default `<script>` — classic JavaScript, and the
     * shape that makes this defect easy to write. `importmap` is here because
     * the map is governed too, and a page whose map is refused loses every ES
     * module on it at once.
     */
    private const EXECUTABLE_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'importmap'];

    /**
     * An attribute name starts here — not in the middle of a longer one.
     *
     * `\b` is the wrong boundary for HTML: `-` is not a word character, so
     * `\bsrc` matches inside `data-src` and `\btype` inside `data-type`. Both
     * misreadings are silent and both fail OPEN: `<script data-type="application/json">`
     * would be taken for a data block and left un-stamped, and an inline
     * `<script data-src="/lazy.js">` with a body of its own would be taken
     * for an external script and never reported.
     *
     * (The closing tag is not written anywhere in this file on purpose:
     * the scanner needs one before it reads a file at all, and the regex
     * literals above would then be findings against themselves.)
     */
    private const ATTRIBUTE_START = '(?<![\w-])';

    /** False for a data block — `application/json`, `application/ld+json` and friends. */
    public static function isExecutable(string $attributes): bool
    {
        if (preg_match('/' . self::ATTRIBUTE_START . 'type\s*=\s*["\']?([^"\'\s>]*)/i', $attributes, $m) !== 1) {
            return true;
        }

        return in_array(strtolower(trim($m[1])), self::EXECUTABLE_TYPES, true);
    }

    /**
     * A real `nonce` attribute — the only thing a browser reads.
     *
     * Use this on a finished document. `data-nonce="hint"` and
     * `id="nonce-bootstrap"` are not nonces, and treating them as one leaves
     * the tag un-stamped under a policy that then refuses it.
     */
    public static function hasNonceAttribute(string $attributes): bool
    {
        return preg_match('/' . self::ATTRIBUTE_START . 'nonce\s*=/i', $attributes) === 1;
    }

    /**
     * True when SOURCE asks for a nonce: the attribute written out, or code
     * that will write one.
     *
     * The scanner cannot evaluate a call, so it reads the name — every
     * framework helper that stamps a nonce says so in its own name, which is
     * why AssetRenderer's private one was renamed rather than allowlisted.
     * What this must NOT accept is the word sitting in someone else's
     * attribute: `data-nonce`, or a value like `id="nonce-bootstrap"`. The
     * difference is context, so each accepted context is spelled out.
     *
     * @var list<string>
     */
    private const NONCE_IN_CODE = [
        // An identifier that ends in a call or a static call:
        // `CspNonce::attribute()`, `csp_nonce_attr()`, `inlineScriptNonceAttributes($x)`.
        '/[\w]*nonce[\w]*\s*(?:\(|::)/i',
        // A variable: `{$nonceAttr}`, or a short-echo tag holding `$nonce`.
        '/\$[\w]*nonce/i',
        // A Twig interpolation, including a bare `{{ nonce }}`.
        '/\{\{[^}]*nonce/i',
    ];

    public static function asksForNonce(string $attributes): bool
    {
        if (self::hasNonceAttribute($attributes)) {
            return true;
        }

        foreach (self::NONCE_IN_CODE as $pattern) {
            if (preg_match($pattern, $attributes) === 1) {
                return true;
            }
        }

        return false;
    }

    /** True when the tag loads its code from elsewhere: allowed by a source list, with or without a nonce. */
    public static function hasSrc(string $attributes): bool
    {
        return preg_match('/' . self::ATTRIBUTE_START . 'src\s*=/i', $attributes) === 1;
    }
}
