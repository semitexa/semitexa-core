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
 */
final class ScriptTag
{
    /** The tag pattern. Bounded to one line: a `>` reached by crossing a newline is an arrow operator. */
    public const PATTERN = '/<script\b([^>\n]*)>/i';

    /**
     * Types the browser EXECUTES, and which script-src therefore governs.
     *
     * The empty string is the default `<script>` — classic JavaScript, and the
     * shape that makes this defect easy to write. `importmap` is here because
     * the map is governed too, and a page whose map is refused loses every ES
     * module on it at once.
     */
    private const EXECUTABLE_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'importmap'];

    /** False for a data block — `application/json`, `application/ld+json` and friends. */
    public static function isExecutable(string $attributes): bool
    {
        if (preg_match('/\btype\s*=\s*["\']?([^"\'\s>]*)/i', $attributes, $m) !== 1) {
            return true;
        }

        return in_array(strtolower(trim($m[1])), self::EXECUTABLE_TYPES, true);
    }

    /**
     * True when the tag already carries a nonce — literally, by interpolation
     * (`{$nonceAttr}`, `{{ csp_nonce_attr() }}`) or through a helper whose
     * name says so (`CspNonce::attribute()`).
     */
    public static function hasNonce(string $attributes): bool
    {
        return stripos($attributes, 'nonce') !== false;
    }

    /** True when the tag loads its code from elsewhere: allowed by a source list, with or without a nonce. */
    public static function hasSrc(string $attributes): bool
    {
        return preg_match('/\bsrc\s*=/i', $attributes) === 1;
    }
}
