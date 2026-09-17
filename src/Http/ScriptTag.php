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
     *
     * The name ends where an element name ends — whitespace, `/` or `>`. `\b`
     * matches before a hyphen too, so `<script-widget>` was read as a script
     * and a valid custom element failed the lint.
     */
    public const PATTERN = '/<script(?=[\s\/>])([^>\n]*)>/i';

    /**
     * Elements whose CONTENT is text rather than markup. A `<script>` written
     * inside one of these is a string that spells a tag, not a tag.
     *
     * @var list<string>
     */
    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp'];

    /**
     * Types the browser EXECUTES, and which script-src therefore governs.
     *
     * The empty string is the default `<script>` — classic JavaScript, and the
     * shape that makes this defect easy to write. `importmap` is here because
     * the map is governed too, and a page whose map is refused loses every ES
     * module on it at once.
     */
    private const EXECUTABLE_TYPES = [
        '',
        'module',
        // Governed by script-src exactly as a classic script is, and refused
        // the same way — a page whose import map or speculation rules are
        // blocked loses every ES module, or all its prefetching, with nothing
        // in the markup to say why.
        'importmap',
        'speculationrules',
        // The JavaScript MIME essences from the MIME Sniffing Standard. The
        // legacy spellings are not decoration: a browser runs them, so a nonce
        // policy refuses them, and a short list called them data.
        'application/ecmascript',
        'application/javascript',
        'application/x-ecmascript',
        'application/x-javascript',
        'text/ecmascript',
        'text/javascript',
        'text/javascript1.0',
        'text/javascript1.1',
        'text/javascript1.2',
        'text/javascript1.3',
        'text/javascript1.4',
        'text/javascript1.5',
        'text/jscript',
        'text/livescript',
        'text/x-ecmascript',
        'text/x-javascript',
    ];

    /*
     * There is no attribute-boundary PATTERN here any more, and that is the
     * point. Three were tried and each read somebody else's value as an
     * attribute: `\b` matches after a hyphen (`data-src` answered for `src`),
     * a whitespace lookbehind still matches inside `data-url="?nonce=old"`,
     * and `x:nonce` is a different attribute ending in the same letters. Every
     * one of those silently exempted an executable tag. {@see self::attributes()}
     * parses the list instead.
     *
     * (The closing tag is not written anywhere in this file on purpose: the
     * scanner needs one before it reads a file at all, and the patterns here
     * would then be findings against themselves.)
     */

    /**
     * Opening `<script …>` tags in SOURCE, quote-aware and still line-bound.
     *
     * The pattern this replaces had both halves of the problem the document
     * scanner already solved. It stopped at a `>` inside a quoted value, so
     * `<script data-x="a>b" nonce="ok">` handed the classifier only
     * `data-x="a` — a correct, nonce-bearing tag reported as a finding. And it
     * could not see a tag whose attributes wrap across lines, so one escaped
     * the lint entirely.
     *
     * What it KEEPS is the newline bound, because source is not markup: a `>`
     * reached by crossing a newline is an arrow operator two lines down, and
     * reading it as the end of a tag reported a helper call as a bare script.
     * The rule is therefore "quotes may not be crossed, and a newline OUTSIDE
     * a quoted value ends the attempt" — which admits the wrapped tag and
     * still refuses the arrow.
     *
     * @return list<array{start: int, attributes: string}>
     */
    public static function sourceTags(string $contents): array
    {
        $tags = [];
        $length = strlen($contents);
        $offset = 0;

        while (($at = stripos($contents, '<script', $offset)) !== false) {
            $offset = $at + 7;

            $after = $contents[$at + 7] ?? '>';
            if ($after !== '>' && $after !== '/' && trim($after) !== '') {
                continue;
            }

            $quote = null;
            for ($i = $at + 7; $i < $length; $i++) {
                $char = $contents[$i];

                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }
                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    continue;
                }

                if ($char === "\n") {
                    // Outside a quoted value a newline ends the attempt: what
                    // follows may be code, and a `>` down there is an arrow.
                    // A tag wrapped INSIDE a quoted value is still read, which
                    // is the case worth admitting.
                    break;
                }

                if ($char === '>') {
                    $tags[] = ['start' => $at, 'attributes' => substr($contents, $at + 7, $i - ($at + 7))];
                    $offset = $i + 1;
                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * Every opening `<script …>` tag in a FINISHED document, in order.
     *
     * A regex is the wrong instrument here and the review that said so was
     * right twice over. `([^>]*)` ends the tag at the first `>`, so
     * `<script data-expr="a > b">` is cut in half and the nonce lands inside
     * the quoted value — a malformed tag with no nonce, which is worse than
     * the tag it replaced. And a pattern applied to the whole document also
     * matches the TEXT `<script>` inside a script body, so
     * `<script type="application/json">{"t":"<script>"}` came back with a
     * nonce stamped into the JSON. That is not a missed nonce, it is a
     * corrupted response.
     *
     * So: a small scanner that knows the two things a regex cannot. Quoted
     * attribute values do not end a tag, and the CONTENT of a raw-text
     * element is text — whatever it spells.
     *
     * Not an HTML parser, and it does not need to be: it answers one question
     * about documents this framework itself rendered.
     *
     * @return list<array{start: int, length: int, attributes: string}>
     */
    public static function documentTags(string $html): array
    {
        $tags = [];
        $length = strlen($html);
        $offset = 0;

        while ($offset < $length) {
            $at = strpos($html, '<', $offset);
            if ($at === false) {
                break;
            }

            // A comment is text that looks like markup.
            if (substr($html, $at, 4) === '<!--') {
                $close = strpos($html, '-->', $at + 4);
                $offset = $close === false ? $length : $close + 3;
                continue;
            }

            $name = self::elementNameAt($html, $at);
            if ($name === null) {
                $offset = $at + 1;
                continue;
            }

            $tagEnd = self::openingTagEnd($html, $at);
            if ($tagEnd === null) {
                break;
            }

            $attributes = substr($html, $at + 1 + strlen($name), $tagEnd - ($at + 1 + strlen($name)));

            if ($name === 'script') {
                $tags[] = ['start' => $at, 'length' => $tagEnd + 1 - $at, 'attributes' => $attributes];
            }

            // NO self-closing exception. `<script />` does not close a script
            // element — HTML allows that syntax only in foreign content — so
            // treating it as closed resumed markup scanning inside the body,
            // and a literal `<script>` written in that body was stamped.
            if (!in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                $offset = $tagEnd + 1;
                continue;
            }

            // Skip the raw-text CONTENT wholesale. Whatever it spells — a
            // closing tag in a JavaScript string, a whole document in a
            // <textarea> — it is text, and nothing in it is a tag.
            $end = self::rawTextEnd($html, $name, $tagEnd + 1);
            $offset = $end ?? $length;
        }

        return $tags;
    }

    /**
     * Offset just past the raw-text closer for `$name`, or null when there is
     * none.
     *
     * A closing tag for `scripture` starts with the same eight characters a
     * closing tag for `script` does, and is NOT the end of a script.
     * Accepted as one, the scan resumes inside the body and stamps the text
     * that follows — which is how a JSON string holding markup came back with
     * an attribute spliced into it. The name has to be followed by whitespace,
     * `/` or `>`, the same boundary rule the region scanner already applies.
     */
    private static function rawTextEnd(string $html, string $name, int $from): ?int
    {
        $closer = '<' . '/' . $name;
        $length = strlen($closer);
        $offset = $from;

        while (true) {
            $at = stripos($html, $closer, $offset);
            if ($at === false) {
                return null;
            }

            $next = $html[$at + $length] ?? '>';
            if ($next === '>' || $next === '/' || trim($next) === '') {
                $tagEnd = strpos($html, '>', $at);

                return $tagEnd === false ? null : $tagEnd + 1;
            }

            $offset = $at + $length;
        }
    }

    /** The element name of a start tag at `$at`, or null when this is not one. */
    private static function elementNameAt(string $html, int $at): ?string
    {
        if (preg_match('/\G<([a-zA-Z][a-zA-Z0-9-]*)/', $html, $m, 0, $at) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * Offset of the `>` that ends the opening tag at `$at`, respecting quoted
     * attribute values, or null when the document ends first.
     */
    private static function openingTagEnd(string $html, int $at): ?int
    {
        $length = strlen($html);
        $quote = null;

        for ($i = $at + 1; $i < $length; $i++) {
            $char = $html[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $i;
            }
        }

        return null;
    }

    /**
     * The attribute list of an opening tag, as a name => value map.
     *
     * Parsed rather than pattern-matched, because every regex tried here read
     * somebody else's VALUE as an attribute. `\b` matches after a hyphen, so
     * `data-src` answered for `src`; a lookbehind for whitespace still matches
     * inside `data-url="?nonce=old"`, and `x:nonce` is a different attribute
     * that ends in the same letters. Each of those silently exempted an
     * executable tag from both the stamp and the lint.
     *
     * Names are lowercased, because HTML attribute names are case-insensitive.
     * A bare attribute (`defer`) maps to the empty string.
     *
     * @return array<string, string>
     */
    public static function attributes(string $attributes): array
    {
        $out = [];
        $length = strlen($attributes);
        $i = 0;

        while ($i < $length) {
            if (ctype_space($attributes[$i]) || $attributes[$i] === '/') {
                $i++;
                continue;
            }

            $start = $i;
            while ($i < $length && !ctype_space($attributes[$i]) && $attributes[$i] !== '=' && $attributes[$i] !== '/') {
                $i++;
            }

            $name = strtolower(substr($attributes, $start, $i - $start));
            if ($name === '') {
                $i++;
                continue;
            }

            while ($i < $length && ctype_space($attributes[$i])) {
                $i++;
            }

            if ($i >= $length || $attributes[$i] !== '=') {
                $out[$name] ??= '';
                continue;
            }

            $i++;
            while ($i < $length && ctype_space($attributes[$i])) {
                $i++;
            }

            if ($i < $length && ($attributes[$i] === '"' || $attributes[$i] === "'")) {
                $quote = $attributes[$i];
                $i++;
                $valueStart = $i;
                while ($i < $length && $attributes[$i] !== $quote) {
                    $i++;
                }
                $out[$name] ??= substr($attributes, $valueStart, $i - $valueStart);
                $i++;
                continue;
            }

            $valueStart = $i;
            while ($i < $length && !ctype_space($attributes[$i])) {
                $i++;
            }
            $out[$name] ??= substr($attributes, $valueStart, $i - $valueStart);
        }

        return $out;
    }

    /**
     * False for a data block — `application/json`, `application/ld+json` and
     * friends.
     *
     * The type is compared by its MIME ESSENCE, which is what a browser uses:
     * `text/javascript; charset=utf-8` is a classic script and executes, and
     * comparing the whole string against a short list called it data and left
     * it nonce-less.
     */
    public static function isExecutable(string $attributes): bool
    {
        $type = self::attributes($attributes)['type'] ?? null;
        if ($type === null) {
            return true;
        }

        $essence = strtolower(trim(explode(';', $type, 2)[0]));

        return in_array($essence, self::EXECUTABLE_TYPES, true);
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
        // A USABLE one. `nonce=""` and a bare `nonce` both parse to the empty
        // string, and an empty nonce matches no policy — read as "already has
        // one", they left the tag blocked and the lint quiet.
        return trim(self::attributes($attributes)['nonce'] ?? '') !== '';
    }

    /**
     * The attribute list with any unusable `nonce` removed.
     *
     * The stamper APPENDS, so it cannot simply skip the emptiness check: two
     * `nonce` attributes on one tag is what the browser would then read, and
     * it honours the first.
     */
    public static function withoutEmptyNonce(string $attributes): string
    {
        if (!array_key_exists('nonce', self::attributes($attributes))) {
            return $attributes;
        }

        if (self::hasNonceAttribute($attributes)) {
            return $attributes;
        }

        return (string) preg_replace(
            '/(?<!\S)nonce(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?/i',
            '',
            $attributes
        );
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

        // Somebody else's ATTRIBUTE VALUE is blanked first, and only that:
        // `<script id="{{ nonce }}">` names a nonce inside an unrelated
        // attribute and writes no nonce at all, so reading it as an ask
        // exempted an executable tag from the whole lint.
        //
        // Matched as `name=` followed by a value, NOT as "any quoted run".
        // This reads SOURCE, where a quote is as likely to be PHP's as the
        // markup's: blanking every quoted span erased the framework's own
        // idiom — `'<script' . CspNonce::attribute() . '>'` — and reported the
        // one emission that is definitely correct.
        //
        // The value may be UNQUOTED — `<script id={{nonce}}>` — and reading
        // only quoted runs left that interpolation in someone else's
        // attribute looking like an ask, which exempted the tag. An unquoted
        // value runs to the next space or `>`, which is HTML's own rule.
        $outsideValues = (string) preg_replace_callback(
            '/[\w:.-]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $attributes
        );

        foreach (self::NONCE_IN_CODE as $pattern) {
            if (preg_match($pattern, $outsideValues) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The SOURCE rule for executability, which is the opposite default.
     *
     * A template writes `type="{{ scriptType }}"`, and the value is not known
     * until it renders. Compared against the executable list it matched
     * nothing and the tag was filed as a data block — so a script that renders
     * as `module` was never reported. Here an unresolved type is EXECUTABLE
     * unless the source says, statically, that it is data.
     */
    public static function isExecutableInSource(string $attributes): bool
    {
        $type = self::attributes($attributes)['type'] ?? null;
        if ($type === null) {
            return true;
        }

        $essence = strtolower(trim(explode(';', $type, 2)[0]));
        if ($essence === '' || in_array($essence, self::EXECUTABLE_TYPES, true)) {
            return true;
        }

        // A literal the browser will read as data — json, ld+json, a template
        // type, anything with no interpolation left in it.
        return preg_match('/[{$<]/', $type) === 1;
    }

    /** True when the tag loads its code from elsewhere: allowed by a source list, with or without a nonce. */
    public static function hasSrc(string $attributes): bool
    {
        return array_key_exists('src', self::attributes($attributes));
    }
}
