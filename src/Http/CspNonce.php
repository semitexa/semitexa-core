<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Support\CoroutineLocal;

/**
 * The CSP nonce of the response being rendered, if the application has one.
 *
 * A project enforcing `script-src 'nonce-…'` stamps that nonce onto the inline
 * scripts it writes — but the framework writes inline scripts of its own, and
 * until every one of them asks here, enabling a nonce policy breaks them one at
 * a time, silently, in the browser. The server's HTML is correct, the response
 * is 200, and only the console knows. That is why this lives in core and not
 * beside the first renderer that needed it: a nonce is a property of the
 * response, and half a dozen packages emit into that response.
 *
 * Two ways in, and the difference matters under Swoole:
 *
 *   - {@see self::set()} — the per-request value. Coroutine-local, so two
 *     requests being served by the same worker never see each other's nonce.
 *     This is what a middleware that generates a nonce per response calls.
 *   - {@see self::register()} — a provider registered once per worker, called
 *     on every read. Use it when the nonce already lives somewhere request-
 *     scoped and this is just a window onto it.
 *
 * A provider that closes over ONE request's nonce is the coroutine trap: the
 * closure is worker-global, the value is not. `set()` exists so that mistake
 * has an obvious alternative.
 *
 * No provider and no value — the default — renders exactly the markup this
 * class predates: zero cost for consumers with no CSP.
 */
final class CspNonce
{
    private const KEY = 'core.csp_nonce';

    /** @var (callable(): string)|null */
    private static $provider = null;

    /**
     * Register the worker-wide provider, or null to clear it.
     *
     * @param (callable(): string)|null $provider
     */
    public static function register(?callable $provider): void
    {
        self::$provider = $provider;
    }

    /** Set the nonce for the request being served on this coroutine. */
    public static function set(string $nonce): void
    {
        CoroutineLocal::set(self::KEY, $nonce);
    }

    /** Forget this coroutine's nonce. Does not touch the provider. */
    public static function clear(): void
    {
        CoroutineLocal::remove(self::KEY);
    }

    /**
     * The raw nonce, or '' when neither a value nor a provider is present.
     *
     * Some consumers need the value rather than a script attribute — a
     * `<meta name="csp-nonce">` that a third-party bundle reads before styling
     * itself, for one. Building that meta by string-slicing attribute() would
     * be a second place to get the escaping wrong.
     */
    public static function value(): string
    {
        $local = CoroutineLocal::get(self::KEY);
        if (is_string($local) && $local !== '') {
            return $local;
        }

        if (self::$provider === null) {
            return '';
        }

        $value = (self::$provider)();

        return is_string($value) ? $value : '';
    }

    /** ` nonce="…"` ready for a `<script` tag, or '' when there is no nonce. */
    public static function attribute(): string
    {
        $nonce = self::value();

        return $nonce === '' ? '' : ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Stamp this response's nonce onto every executable `<script>` in a
     * finished document that does not already carry one.
     *
     * For the pages that are written as one string — a standalone document in
     * a handler, an app served into an iframe — where threading a variable
     * into a nowdoc means either interpolating a body full of `$` or writing
     * the attribute by hand in a dozen places. A no-op when the application
     * has no nonce, so it costs one substring search and nothing else.
     *
     * Executable is the operative word: a `type="application/json"` block is
     * data and is left exactly as it was. The rule is {@see ScriptTag}'s, the
     * same one the lint reports against.
     *
     * Only real opening tags are touched. {@see ScriptTag::documentTags()}
     * skips the CONTENT of a raw-text element, so a `<script>` written inside
     * a JavaScript string or a JSON block stays a string: stamping it turned
     * a valid response into a broken one, which is worse than the missing
     * nonce this method exists to add.
     */
    public static function stamp(string $html): string
    {
        $attribute = self::attribute();
        // stripos, not str_contains: the scan below is case-insensitive
        // because HTML tag names are, and a fast path that disagrees with
        // the rule it guards is just a way of skipping `<SCRIPT>` quietly.
        if ($attribute === '' || stripos($html, '<script') === false) {
            return $html;
        }

        // Applied back to front, so each splice leaves the offsets of the ones
        // still to come untouched.
        foreach (array_reverse(ScriptTag::documentTags($html)) as $tag) {
            $attributes = $tag['attributes'];

            if (ScriptTag::hasNonceAttribute($attributes) || !ScriptTag::isExecutable($attributes)) {
                continue;
            }

            // A self-closing `<script … />` puts the slash last: appending
            // after it produces `<script src="x"/ nonce="…">`, which the
            // parser reads as an attribute named `/`. Rare in HTML and
            // common in hand-written XHTML-ish markup, so it is cheaper to
            // handle than to forbid.
            $replacement = str_ends_with(rtrim($attributes), '/')
                ? '<script' . rtrim(substr(rtrim($attributes), 0, -1)) . $attribute . '/>'
                : '<script' . $attributes . $attribute . '>';

            $html = substr_replace($html, $replacement, $tag['start'], $tag['length']);
        }

        return $html;
    }

    /** Test seam: drop both the provider and this coroutine's value. */
    public static function reset(): void
    {
        self::$provider = null;
        self::clear();
    }
}
