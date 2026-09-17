<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\InlineScriptOwner;
use Semitexa\Core\Http\InlineScriptScanner;

/**
 * What the scanner calls broken, and — just as load-bearing — what it leaves
 * alone. A lint that cries about data blocks and docblocks gets switched off,
 * and a switched-off lint is worse than none because it reads as coverage.
 */
final class InlineScriptScannerTest extends TestCase
{
    private InlineScriptScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new InlineScriptScanner();
    }

    #[Test]
    public function aBareInlineScriptIsAFinding(): void
    {
        $findings = $this->scan("<html>\n<body>\n<script>alert(1)</script>\n</body>");

        self::assertCount(1, $findings);
        self::assertSame(3, $findings[0]->line);
        self::assertSame('<script>', $findings[0]->snippet);
        self::assertSame(InlineScriptOwner::Framework, $findings[0]->owner);
    }

    #[Test]
    public function theRegressionThisWholeCheckExistsFor(): void
    {
        // The exact string semitexa/ssr shipped, which no server-side test
        // could fail on and every nonce-enforcing consumer paid for.
        $findings = $this->scan("return '<script>window.__SSR_DEFERRED=' . \$json . ';</script>';");

        self::assertCount(1, $findings);
    }

    #[Test]
    public function theShapeThatReplacedItIsClean(): void
    {
        $findings = $this->scan("'<script type=\"application/json\" data-ssr-deferred-manifest>' . \$json . '</script>'");

        self::assertSame([], $findings);
    }

    /** @return iterable<string, array{string}> */
    public static function safeTags(): iterable
    {
        yield 'external src' => ['<script src="/assets/app.js" defer></script>'];
        yield 'json data block' => ['<script type="application/json" id="m">{}</script>'];
        yield 'ld+json' => ['<script type="application/ld+json">{}</script>'];
        yield 'literal nonce' => ['<script nonce="abc123">go()</script>'];
        yield 'heredoc interpolation' => ['<script{$nonceAttr}>go()</script>'];
        yield 'php concatenation' => ["'<script' . CspNonce::attribute() . '>go()</script>'"];
        yield 'importmap with nonce' => ['<script type="importmap" nonce="abc">{}</script>'];
        yield 'uppercase SRC' => ['<SCRIPT SRC="/a.js"></SCRIPT>'];
    }

    #[Test]
    #[DataProvider('safeTags')]
    public function safeTagsAreNotReported(string $markup): void
    {
        self::assertSame([], $this->scan($markup), $markup . ' must not be reported');
    }

    #[Test]
    public function anImportMapWithoutANonceIsAFinding(): void
    {
        // importmap is not in the executable-type list by accident: script-src
        // governs it, and a page whose map is refused loses every ES module
        // on it, which looks nothing like a CSP problem from the outside.
        $findings = $this->scan('<script type="importmap">{"imports":{}}</script>');

        self::assertCount(1, $findings);
    }

    #[Test]
    public function proseAboutScriptTagsIsNotCode(): void
    {
        $source = <<<'PHP'
        <?php
        /**
         * Emits an empty `<script>` when the component has no events.
         */
        // <script> in a line comment is prose too
        {# <script> in a Twig comment #}
        PHP;

        self::assertSame([], $this->scan($source));
    }

    #[Test]
    public function proseOnTheSameLineAsRealCodeIsStillScanned(): void
    {
        // The prose rule keys on how the LINE starts, so a trailing comment
        // cannot hide an emission that precedes it on the same line.
        $findings = $this->scan("echo '<script>x()</script>'; // emits <script> for the widget");

        self::assertCount(1, $findings);
    }

    #[Test]
    public function everyFindingCarriesItsPathAndOwner(): void
    {
        $findings = $this->scanner->scan(
            'src/modules/Console/templates/page.html.twig',
            "<script>go()</script>",
            InlineScriptOwner::Application,
        );

        self::assertSame('src/modules/Console/templates/page.html.twig', $findings[0]->path);
        self::assertSame(InlineScriptOwner::Application, $findings[0]->owner);
        self::assertSame(
            ['path' => 'src/modules/Console/templates/page.html.twig', 'line' => 1, 'snippet' => '<script>', 'owner' => 'application'],
            $findings[0]->toArray(),
        );
    }

    #[Test]
    public function severalTagsInOneFileAreReportedSeparatelyWithTheirOwnLines(): void
    {
        $source = "<script>a()</script>\n<script src=\"/b.js\"></script>\n<script>c()</script>";

        $findings = $this->scan($source);

        self::assertSame([1, 3], array_map(static fn ($f) => $f->line, $findings));
    }

    #[Test]
    public function aLongTagIsTruncatedForDisplay(): void
    {
        $findings = $this->scan('<script data-x="' . str_repeat('y', 300) . '">go()</script>');

        self::assertLessThanOrEqual(120, mb_strlen($findings[0]->snippet));
        self::assertStringEndsWith('…', $findings[0]->snippet);
    }

    #[Test]
    public function proseWithNoCloserIsNotAnEmission(): void
    {
        // The two shapes that produced every false positive on the first run
        // over this repository: a help string and a regex literal. Neither
        // file writes a closing tag, because neither emits anything.
        $help = "->setDescription('Find inline <script> blocks a strict CSP refuses');";
        $regex = "preg_match_all('/<script\\b([^>]*)>/i', \$contents, \$m);";

        self::assertSame([], $this->scan($help));
        self::assertSame([], $this->scan($regex));
    }

    #[Test]
    public function aTagIsNotAssembledAcrossALineBreak(): void
    {
        // `$entry->` ends with a `>`. Without the newline bound the scanner
        // read an arrow operator two lines down as the end of a tag and
        // reported a helper call as a bare script.
        $source = "str_contains(\$contents, '<script')
    ? \$entry->attributes
    : [];
</script>";

        self::assertSame([], $this->scan($source));
    }

    #[Test]
    public function aNonceBearingHelperOnTheTagIsEnough(): void
    {
        // The scanner cannot evaluate a call, so it reads the name. Every
        // framework helper that stamps a nonce says so in its own name —
        // which is why AssetRenderer's private one was renamed rather than
        // allowlisted here.
        $source = "return '<script' . self::inlineScriptNonceAttributes(\$entry->attributes) . '>' . \$js . '</script>';";

        self::assertSame([], $this->scan($source));
    }

    /**
     * @return iterable<string, array{string}>
     *
     * Every one of these is a tag a browser executes and a nonce policy
     * refuses, which the word-boundary rules used to read as safe. The word
     * `nonce` is not a nonce, `data-src` is not a `src`, and
     * `data-type="application/json"` is not a data block — in all three the
     * lint reported the file clean while the script was blocked.
     */
    public static function tagsWearingSomebodyElsesAttribute(): iterable
    {
        yield 'data-nonce is a hint, not a nonce' => ['<script data-nonce="hint">go()</script>'];
        yield 'nonce inside a value' => ['<script id="nonce-bootstrap">go()</script>'];
        yield 'data-src still runs its own body' => ['<script data-src="/lazy.js">run()</script>'];
        yield 'data-type is not the type' => ['<script data-type="application/json">go()</script>'];
    }

    #[Test]
    #[DataProvider('tagsWearingSomebodyElsesAttribute')]
    public function anAttributeThatMerelyLooksLikeTheRealOneIsStillAFinding(string $markup): void
    {
        self::assertCount(1, $this->scan($markup), $markup . ' is executable and carries no nonce');
    }

    #[Test]
    public function caseDoesNotHideAnEmission(): void
    {
        // HTML tag names are case-insensitive and the tag pattern is too, so
        // the closing-tag precheck must be. It was not, and an uppercase
        // emission left the whole file unscanned.
        $findings = $this->scan("<SCRIPT>alert(1)</SCRIPT>");

        self::assertCount(1, $findings);
    }

    #[Test]
    public function aTwigNonceVariableIsAnAsk(): void
    {
        self::assertSame([], $this->scan('<script{{ csp_nonce_attr() }}>go()</script>'));
    }

    #[Test]
    public function aNonceSpeltInsideAnotherAttributesValueIsNotAnAsk(): void
    {
        // `<script id="{{ nonce }}">` writes no nonce at all. Read as an ask,
        // it exempted an executable tag from the whole check.
        self::assertCount(1, $this->scan('<script id="{{ nonce }}">go()</script>'));
        self::assertCount(1, $this->scan('<script data-url="?nonce=old">go()</script>'));
        self::assertCount(1, $this->scan('<script x:nonce="not-it">go()</script>'));
    }

    #[Test]
    public function aTypeWithParametersIsNotADataBlock(): void
    {
        self::assertCount(1, $this->scan('<script type="text/javascript; charset=utf-8">go()</script>'));
    }

    #[Test]
    public function aTypeTheTemplateHasNotResolvedYetIsTreatedAsExecutable(): void
    {
        // Source, not a document: `type="{{ scriptType }}"` is not known until
        // it renders. Compared against the executable list it matched nothing
        // and the tag was filed as a data block, so a script that renders as
        // `module` was never reported.
        self::assertCount(1, $this->scan('<script type="{{ scriptType }}">go()</script>'));
        self::assertSame([], $this->scan('<script type="application/json">{}</script>'));
    }

    #[Test]
    public function aQuotedAngleBracketDoesNotHideTheRestOfTheTag(): void
    {
        // The source pattern stopped at the `>` inside the value, so the
        // classifier saw `data-x="a` and never reached the nonce — a correct,
        // nonce-bearing tag reported as a finding. A lint that flags correct
        // code is one people switch off.
        self::assertSame([], $this->scan('<script data-x="a>b" nonce="ok">go()</script>'));
        self::assertCount(1, $this->scan('<script data-x="a>b">go()</script>'));
    }

    #[Test]
    public function aTagWrappedInsideAQuotedValueIsStillOneTag(): void
    {
        // A newline INSIDE a quoted value is part of the value, not the end of
        // the attempt: the tag is still a tag.
        $source = "<script data-json=\"{\n  \\\"a\\\": 1\n}\" nonce=\"ok\">go()</script>";

        self::assertSame([], $this->scan($source));
    }

    #[Test]
    public function aStampCallMayBeSpacedAndCommented(): void
    {
        // PHP allows whitespace and comments between the tokens. A prefilter
        // pinned to the compact spelling rejected a formatted call before the
        // tokens were consulted, and the file's correct emission was reported.
        $source = "<?php\n\$html = '<script>go()</script>';\n"
            . "return CspNonce /* the response's own */ :: stamp(\$html);";

        self::assertSame([], $this->scan($source));
    }

    #[Test]
    public function aFileWithNoScriptsCostsNothing(): void
    {
        self::assertSame([], $this->scan('<?php return 1;'));
    }

    /** @return list<\Semitexa\Core\Http\InlineScriptFinding> */
    #[Test]
    public function aLookalikeStamperDoesNotExemptTheFile(): void
    {
        // The exemption used to be "a token ENDING in CspNonce", which a test
        // double satisfies. A file that calls a fake stamps nothing, and the
        // bare tag below reaches the browser exactly as if the check had
        // never run.
        $source = "<?php\n\$html = '<script>go()</script>';\nreturn FakeCspNonce::stamp(\$html);";

        self::assertCount(1, $this->scan($source), 'FakeCspNonce is not the stamper');
    }

    #[Test]
    public function aFullyQualifiedStamperExemptsTheFile(): void
    {
        // The other half of the same bug: a qualified name is its own token
        // type in PHP 8, so a file calling the stamper by its full name was
        // not matched at all and had its correct emission reported.
        $source = "<?php\n\$html = '<script>go()</script>';\n"
            . "return \\Semitexa\\Core\\Http\\CspNonce::stamp(\$html);";

        self::assertSame([], $this->scan($source));
    }

    #[Test]
    public function aTemplateTagSpanningLinesIsStillFound(): void
    {
        // Source keeps a newline bound because a `>` one line down is an
        // arrow operator. A template has no arrow, and the bound cost it the
        // finding entirely — silence, which for a lint is the worse failure.
        $twig = "<div>\n<script\n    type=\"module\">\n  go();\n</script>\n</div>";

        $findings = $this->scanner->scan('packages/semitexa-x/templates/page.html.twig', $twig, InlineScriptOwner::Framework);

        self::assertCount(1, $findings, 'a wrapped opening tag in a template is an emission like any other');
        self::assertSame(2, $findings[0]->line);
    }

    #[Test]
    public function aNonceNamedInAnUnquotedAttributeIsNotAnAsk(): void
    {
        // `id={{nonce}}` writes an id, not a nonce. Only quoted values were
        // blanked before the ask was read, so this interpolation looked like
        // one and exempted an executable tag from the whole lint.
        self::assertCount(1, $this->scan('<script id={{nonce}}>go()</script>'));
        self::assertCount(1, $this->scan('<script data-x=nonce-bootstrap>go()</script>'));
    }

    private function scan(string $contents): array
    {
        return $this->scanner->scan('packages/semitexa-x/src/Thing.php', $contents, InlineScriptOwner::Framework);
    }
}
