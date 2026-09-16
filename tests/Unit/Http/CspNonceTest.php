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

        self::assertStringContainsString('<script src="/a.js" nonce="abc123"/>', $html);
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
    public function resetDropsBothTheProviderAndTheValue(): void
    {
        CspNonce::register(static fn (): string => 'worker-wide');
        CspNonce::set('this-request');

        CspNonce::reset();

        self::assertSame('', CspNonce::value());
    }
}
