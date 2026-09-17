<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\CspNonce;

/**
 * The nonce a response carries, and the two ways it gets there.
 *
 * The distinction these tests pin is not cosmetic: a provider is worker-wide
 * and a value is coroutine-local, so a provider closing over one request's
 * nonce is the sharing bug `set()` exists to avoid. The precedence test is the
 * one that makes that choice safe — a request that sets its own value is never
 * overruled by whatever the worker registered at boot.
 */
final class CspNonceTest extends TestCase
{
    protected function setUp(): void
    {
        CspNonce::reset();
    }

    protected function tearDown(): void
    {
        CspNonce::reset();
    }

    #[Test]
    public function withoutNonceItRendersNothingAtAll(): void
    {
        self::assertSame('', CspNonce::value());
        self::assertSame('', CspNonce::attribute());
    }

    #[Test]
    public function aSetValueIsReadBack(): void
    {
        CspNonce::set('r4nd0m==');

        self::assertSame('r4nd0m==', CspNonce::value());
        self::assertSame(' nonce="r4nd0m=="', CspNonce::attribute());
    }

    #[Test]
    public function aRegisteredProviderIsConsultedOnEveryRead(): void
    {
        $calls = 0;
        CspNonce::register(static function () use (&$calls): string {
            $calls++;

            return 'n-' . $calls;
        });

        self::assertSame('n-1', CspNonce::value());
        self::assertSame('n-2', CspNonce::value());
        self::assertSame(2, $calls, 'the provider is a window onto live state, not a cached value');
    }

    #[Test]
    public function theRequestValueWinsOverTheWorkerProvider(): void
    {
        CspNonce::register(static fn (): string => 'worker-wide');
        CspNonce::set('this-request');

        self::assertSame('this-request', CspNonce::value());
    }

    #[Test]
    public function clearingTheValueFallsBackToTheProvider(): void
    {
        CspNonce::register(static fn (): string => 'worker-wide');
        CspNonce::set('this-request');
        CspNonce::clear();

        self::assertSame('worker-wide', CspNonce::value());
    }

    #[Test]
    public function anEmptyProviderResultIsTreatedAsNoNonce(): void
    {
        CspNonce::register(static fn (): string => '');

        self::assertSame('', CspNonce::value());
        self::assertSame('', CspNonce::attribute(), 'an empty nonce attribute would be worse than none: it matches nothing');
    }

    #[Test]
    public function registeringNullDropsTheProvider(): void
    {
        CspNonce::register(static fn (): string => 'gone');
        CspNonce::register(null);

        self::assertSame('', CspNonce::value());
    }

    #[Test]
    public function theAttributeEscapesAValueThatTriesToLeaveIt(): void
    {
        CspNonce::set('"><script>alert(1)</script>');

        $attribute = CspNonce::attribute();

        self::assertStringNotContainsString('<script>', $attribute);
        self::assertStringNotContainsString('">', $attribute);
        self::assertStringContainsString('&quot;&gt;', $attribute);
    }

    #[Test]
    public function stampingPutsTheNonceOnEveryExecutableScript(): void
    {
        CspNonce::set('abc123');

        $html = CspNonce::stamp(
            '<html><head><script type="module">a()</script></head>'
            . '<body><script>b()</script><script src="/c.js"></script></body></html>'
        );

        self::assertStringContainsString('<script type="module" nonce="abc123">', $html);
        self::assertStringContainsString('<script nonce="abc123">b()', $html);
        self::assertStringContainsString('<script src="/c.js" nonce="abc123">', $html);
    }

    #[Test]
    public function stampingLeavesDataBlocksAlone(): void
    {
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script type="application/json" id="m">{"a":1}</script>');

        self::assertSame('<script type="application/json" id="m">{"a":1}</script>', $html);
    }

    #[Test]
    public function stampingDoesNotDoubleNonceATagThatHasOne(): void
    {
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script nonce="already">a()</script>');

        self::assertSame(1, substr_count($html, 'nonce='));
        self::assertStringContainsString('nonce="already"', $html);
    }

    #[Test]
    public function stampingASelfClosingTagDoesNotProduceASlashAttribute(): void
    {
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script src="/a.js" />');

        // The author's spacing survives — the nonce is spliced in before the
        // separator that was already there, not appended to a rebuilt tag.
        self::assertSame('<script src="/a.js" nonce="abc123" />', $html);
        self::assertStringNotContainsString('/ nonce', $html, 'the slash would be read as an attribute name');
    }

    #[Test]
    public function stampingIsByteIdenticalWithoutANonce(): void
    {
        $document = '<html><body><script>b()</script></body></html>';

        self::assertSame($document, CspNonce::stamp($document));
    }

    #[Test]
    public function stampingLeavesTheScriptBodyUntouched(): void
    {
        // A document written as a nowdoc is full of `$` and `<` that a naive
        // interpolation would eat. The stamper only ever rewrites the opening
        // tag, which is why it can be applied to a finished page.
        CspNonce::set('abc123');
        $body = 'var $x = a < b ? "<b>" : "</b>";';

        $html = CspNonce::stamp('<script>' . $body . '</script>');

        self::assertStringContainsString($body, $html);
        self::assertStringContainsString('<script nonce="abc123">', $html);
    }

    #[Test]
    public function stampingReachesAnOpeningTagWrittenAcrossLines(): void
    {
        // A formatted document is not a defect, and the pattern that bounds a
        // tag to one line exists for SOURCE, where a `>` after a newline is an
        // arrow operator. Read a document with it and a wrapped tag is served
        // nonce-less — refused by the browser, 200 on the server.
        CspNonce::set('abc123');

        $html = CspNonce::stamp("<script\n  type=\"module\"\n  defer>go()</script>");

        self::assertStringContainsString('nonce="abc123"', $html);
    }

    #[Test]
    public function stampingIsBlindToCase(): void
    {
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<SCRIPT>alert(1)</SCRIPT>');

        self::assertStringContainsString('nonce="abc123"', $html, 'the fast path must not disagree with the rule it guards');
    }

    #[Test]
    public function anAttributeThatMerelyContainsTheWordIsNotANonce(): void
    {
        // `stripos($attributes, 'nonce')` read all three of these as "already
        // has one" and left them alone, which under a nonce policy is the
        // silent block this class exists to prevent.
        CspNonce::set('abc123');

        foreach (['<script data-nonce="hint">a()</script>', '<script id="nonce-bootstrap">a()</script>'] as $markup) {
            self::assertStringContainsString('nonce="abc123"', CspNonce::stamp($markup), $markup);
        }
    }

    #[Test]
    public function aLookalikeTypeAttributeDoesNotBuyAnExemption(): void
    {
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script data-type="application/json">go()</script>');

        self::assertStringContainsString('nonce="abc123"', $html, 'data-type is not the type; this tag executes');
    }

    #[Test]
    public function stampingNeverTouchesTextInsideAScript(): void
    {
        // The worst shape this class can produce is not a missing nonce, it is
        // a CORRUPTED response: a pattern run over the whole document matches
        // the TEXT `<script>` inside a body and writes an attribute into a
        // JSON string or a JavaScript literal.
        CspNonce::set('abc123');

        $json = '<script type="application/json" id="d">{"tag":"<script>"}</script>';
        self::assertSame($json, CspNonce::stamp($json), 'a data block and its contents are left alone');

        $js = '<script>var open = "<script>";</script>';
        $stamped = CspNonce::stamp($js);

        self::assertStringContainsString('<script nonce="abc123">', $stamped);
        self::assertStringContainsString('var open = "<script>";', $stamped, 'the body is not markup');
        self::assertSame(1, substr_count($stamped, 'nonce='));
    }

    #[Test]
    public function aQuotedAngleBracketDoesNotCutTheTagInHalf(): void
    {
        // `[^>]*` ends the tag at the first `>`, so the nonce landed inside the
        // quoted value: a malformed tag with no nonce, which is worse than the
        // one it replaced.
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script data-expression="a > b">go()</script>');

        self::assertStringContainsString('data-expression="a > b"', $html, 'the value survived intact');
        self::assertStringContainsString('nonce="abc123"', $html);
        self::assertStringEndsWith('>go()</script>', $html);
    }

    #[Test]
    public function aNonceSpeltInsideAnotherAttributesValueIsNotANonce(): void
    {
        CspNonce::set('abc123');

        foreach ([
            '<script data-url="?nonce=old">a()</script>',
            '<script x:nonce="not-the-attribute">a()</script>',
        ] as $markup) {
            self::assertStringContainsString(' nonce="abc123"', CspNonce::stamp($markup), $markup);
        }
    }

    #[Test]
    public function aTypeWithParametersIsStillJavaScript(): void
    {
        // Browsers decide by MIME ESSENCE. Comparing the whole string against
        // a short list called this a data block and served it nonce-less.
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script type="text/javascript; charset=utf-8">go()</script>');

        self::assertStringContainsString('nonce="abc123"', $html);
    }

    #[Test]
    public function aScriptWrittenInsideATextareaIsNotAScript(): void
    {
        CspNonce::set('abc123');

        $html = '<textarea name="snippet"><script>go()</script></textarea>';

        self::assertSame($html, CspNonce::stamp($html));
    }

    #[Test]
    public function anUnquotedUrlKeepsItsTrailingSlash(): void
    {
        // The self-closing branch cuts the last character off. In
        // `<script src=/a.js/>` that slash is the end of an UNQUOTED VALUE,
        // not the close of the tag, and cutting it changed the URL being
        // loaded — quietly, into one that may still resolve.
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script src=/a.js/>');

        self::assertStringContainsString('src=/a.js/', $html);
        self::assertStringContainsString('nonce="abc123"', $html);
    }

    #[Test]
    public function aTagWithUnquotedAttributesIsStillClassified(): void
    {
        CspNonce::set('abc123');

        self::assertStringContainsString('nonce="abc123"', CspNonce::stamp('<script type=module>go()</script>'));
        self::assertSame(
            '<script type=application/json>{}</script>',
            CspNonce::stamp('<script type=application/json>{}</script>'),
        );
    }

    #[Test]
    public function anEmptyNonceIsNotANonce(): void
    {
        // `nonce=""` and a bare `nonce` both parse to the empty string, and an
        // empty nonce matches no policy. Read as "already has one" they left
        // the tag blocked and the lint quiet; appending beside one leaves TWO,
        // and the browser honours the first.
        CspNonce::set('abc123');

        foreach (['<script nonce="">a()</script>', '<script nonce>a()</script>'] as $markup) {
            $html = CspNonce::stamp($markup);

            self::assertSame(1, substr_count($html, 'nonce='), $markup);
            self::assertStringContainsString('nonce="abc123"', $html, $markup);
        }
    }

    #[Test]
    public function aSolidusDoesNotCloseAScriptElement(): void
    {
        // HTML allows self-closing syntax only in foreign content, so
        // `<script />` leaves the element OPEN. Treating it as closed resumed
        // markup scanning inside the body and stamped the text there.
        CspNonce::set('abc123');

        $document = '<script type="application/json" />{"t":"<script>"}</script>';

        // Asserted EXACTLY, not by absence: the tag is a data block, so the
        // whole document comes back byte for byte. The negative alone was
        // satisfied by an empty string, so it could not fail if stamp()
        // returned nothing at all for this input.
        self::assertSame($document, CspNonce::stamp($document), 'a data block is returned unchanged, body included');
    }

    #[Test]
    public function theWordNonceInsideAnotherAttributeIsNotANonceToReplace(): void
    {
        // The unusable-nonce branch searched the whole tag, so this matched
        // the word inside someone else's VALUE and overwrote it: the document
        // came back corrupted and the script still had no nonce, which is a
        // stamp damaging the page it was meant to make safe.
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script data-desc="set the nonce here">go()</script>');

        self::assertStringContainsString('data-desc="set the nonce here"', $html, 'the other value is untouched');
        self::assertStringContainsString('nonce="abc123"', $html, 'and the tag really gets one');
    }

    #[Test]
    public function anUnusableNonceIsStillReplacedWhenAnotherValueNamesIt(): void
    {
        // The ordering half of the same defect: with the word appearing first
        // inside another value, the replacement landed there instead of on the
        // real, empty nonce attribute.
        CspNonce::set('abc123');

        $html = CspNonce::stamp('<script data-desc="the nonce goes here" nonce="">go()</script>');

        self::assertStringContainsString('data-desc="the nonce goes here"', $html);
        self::assertStringContainsString('nonce="abc123"', $html);
        self::assertStringNotContainsString('nonce=""', $html, 'the empty one is replaced, not left beside a new one');
    }

    #[Test]
    public function aLegacyJavaScriptTypeStillExecutes(): void
    {
        CspNonce::set('abc123');

        foreach (['text/ecmascript', 'application/x-javascript', 'text/jscript'] as $type) {
            $html = CspNonce::stamp('<script type="' . $type . '">go()</script>');

            self::assertStringContainsString('nonce="abc123"', $html, $type);
        }
    }

    #[Test]
    public function resetDropsBothTheProviderAndTheValue(): void
    {
        CspNonce::register(static fn (): string => 'worker-wide');
        CspNonce::set('this-request');

        CspNonce::reset();

        self::assertSame('', CspNonce::value());
    }
}
