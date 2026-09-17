<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Http\ScriptTag;

/**
 * ONE INVARIANT, over many shapes: stamping only ADDS nonce attributes.
 *
 * Written after the third separate defect in which `stamp()` changed something
 * else — text inside a script body, a quoted `>` that cut a tag in half, the
 * last character of an unquoted URL. Each was found by a reviewer naming one
 * example, each was fixed, and each time the next example was somewhere new.
 *
 * Case-by-case tests cannot close that, because the defect is always in the
 * case nobody wrote down. This asserts the PROPERTY instead: take the stamped
 * document, remove the nonce attributes it added, and what is left has to be
 * the input, byte for byte. Anything else — a dropped slash, a spliced string,
 * a re-ordered attribute — fails here without anyone having predicted it.
 */
final class StampingIsAdditiveTest extends TestCase
{
    protected function setUp(): void
    {
        CspNonce::reset();
    }

    protected function tearDown(): void
    {
        CspNonce::reset();
    }

    /**
     * How many EXECUTABLE script tags each piece contributes — declared here
     * rather than measured, so the assertion cannot agree with a scanner that
     * sees nothing.
     *
     * Zero for a data block, and for markup written as TEXT: inside a comment,
     * a textarea, a style, or a paragraph.
     *
     * @var array<string, int>
     */
    private const EXECUTABLE_TAGS = [
        'plain' => 1,
        'module' => 1,
        'json with a tag inside' => 0,
        'js string holding markup' => 1,
        'quoted gt' => 1,
        'unquoted url' => 1,
        'unquoted url with slash' => 1,
        'self closing' => 1,
        'wrapped tag' => 1,
        'uppercase' => 1,
        'single quotes' => 0,
        'data-nonce' => 1,
        'real nonce' => 1,
        'empty nonce' => 1,
        'bare nonce' => 1,
        'legacy type' => 1,
        'type with charset' => 1,
        'external' => 1,
        'importmap' => 1,
        'speculation' => 1,
        'comment holding a tag' => 0,
        'textarea holding a tag' => 0,
        'style holding a tag' => 0,
        'lookalike closer' => 1,
        'prose' => 0,
    ];

    /** @return iterable<string, array{string, int}> */
    public static function documents(): iterable
    {
        $pieces = [
            'plain' => '<script>go()</script>',
            'module' => '<script type="module">import "x";</script>',
            'json with a tag inside' => '<script type="application/json">{"t":"<script>","u":"</scr"+"ipt>"}</script>',
            'js string holding markup' => '<script>var s = "<div></div>";</script>',
            'quoted gt' => '<script data-expr="a > b">go()</script>',
            'unquoted url' => '<script src=/a.js></script>',
            'unquoted url with slash' => '<script src=/a.js/></script>',
            'self closing' => '<script src="/b.js" /></script>',
            'wrapped tag' => "<script\n  type=\"module\"\n  defer>go()</script>",
            'uppercase' => '<SCRIPT TYPE="Module">go()</SCRIPT>',
            'single quotes' => "<script type='application/json'>{}</script>",
            'data-nonce' => '<script data-nonce="hint">go()</script>',
            'real nonce' => '<script nonce="already">go()</script>',
            'empty nonce' => '<script nonce="">go()</script>',
            'bare nonce' => '<script nonce>go()</script>',
            'legacy type' => '<script type="text/jscript">go()</script>',
            'type with charset' => '<script type="text/javascript; charset=utf-8">go()</script>',
            'external' => '<script src="/c.js" defer></script>',
            'importmap' => '<script type="importmap">{}</script>',
            'speculation' => '<script type="speculationrules">{}</script>',
            'comment holding a tag' => '<!-- <script>hidden()</script> -->',
            'textarea holding a tag' => '<textarea><script>typed()</script></textarea>',
            'style holding a tag' => '<style>/* <script>x</script> */</style>',
            'lookalike closer' => '<script>var t = "</scripture>";</script>',
            'prose' => '<p>A paragraph mentioning &lt;script&gt; safely.</p>',
        ];

        foreach ($pieces as $name => $piece) {
            yield $name => [
                '<!doctype html><html><body>' . $piece . '</body></html>',
                self::EXECUTABLE_TAGS[$name],
            ];
        }

        // Ordered pairs, but only among the pieces where ORDER can matter:
        // a raw-text body, a comment, or anything holding text that spells a
        // tag. That is where every defect in this family lived — the scan
        // resumed in the wrong place and mishandled whatever came next — and
        // it is a small enough set to pair exhaustively without turning one
        // invariant into a thousand test cases.
        $ordered = [
            'plain',
            'json with a tag inside',
            'js string holding markup',
            'comment holding a tag',
            'textarea holding a tag',
            'style holding a tag',
            'lookalike closer',
            'self closing',
        ];

        foreach ($ordered as $first) {
            foreach ($ordered as $second) {
                if ($first === $second) {
                    continue;
                }

                yield $first . ' + ' . $second => [
                    '<!doctype html><html><body>' . $pieces[$first] . "\n" . $pieces[$second] . '</body></html>',
                    self::EXECUTABLE_TAGS[$first] + self::EXECUTABLE_TAGS[$second],
                ];
            }
        }
    }

    #[Test]
    #[DataProvider('documents')]
    public function stampingAddsNoncesAndChangesNothingElse(string $html, int $expected): void
    {
        CspNonce::set('n0nc3');

        $stamped = CspNonce::stamp($html);

        self::assertSame(
            self::withoutNonces($html),
            self::withoutNonces($stamped),
            'stamping changed something that was not a nonce',
        );
    }

    #[Test]
    #[DataProvider('documents')]
    public function everyExecutableTagEndsUpWithExactlyOneUsableNonce(string $html, int $expected): void
    {
        CspNonce::set('n0nc3');

        $executable = 0;

        foreach (ScriptTag::documentTags(CspNonce::stamp($html)) as $tag) {
            if (!ScriptTag::isExecutable($tag['attributes'])) {
                continue;
            }

            $executable++;

            $attributes = ScriptTag::attributes($tag['attributes']);

            self::assertArrayHasKey('nonce', $attributes, 'executable and unstamped: ' . trim($tag['attributes']));
            self::assertNotSame('', trim($attributes['nonce']), 'an empty nonce matches no policy');
            self::assertSame(
                1,
                preg_match_all('/(?<!\S)nonce(\s*=|[\s>])/i', $tag['attributes'] . '>'),
                'more than one nonce attribute: ' . trim($tag['attributes']),
            );
        }

        // The EXPECTED count, declared by the fixture. `>= 0` was there only
        // to keep PHPUnit from calling the case risky, and it asserted
        // nothing — a scanner that found no tags at all passed it. Asking the
        // scanner what it expects would be the same circle one step further
        // out, so the number comes from the piece list instead, and an exact
        // count also catches a document where only SOME tags were missed.
        self::assertSame($expected, $executable, 'the document did not yield the executable tags it is built from');
    }

    /**
     * Every nonce attribute gone, from both sides of the comparison.
     *
     * Not "the ones the stamper added": replacing an UNUSABLE nonce in place
     * is a mutation the stamper is allowed to make, and the invariant is about
     * everything that is not a nonce. What survives here is the rest of the
     * document, which has to be identical.
     */
    private static function withoutNonces(string $html): string
    {
        // The WHITESPACE BEFORE the attribute goes with it, and at least one
        // space is required — otherwise `data-nonce` is eaten too, and the
        // comparison stops seeing a difference it is meant to catch.
        return (string) preg_replace(
            '/\s+nonce(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\/>]*))?(?=[\s\/>])/i',
            '',
            $html
        );
    }
}
