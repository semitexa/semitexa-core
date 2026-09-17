<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\InlineScriptOwner;
use Semitexa\Core\Http\InlineScriptSweep;

/**
 * The traversal, against a real directory rather than console output.
 *
 * Two things decide whether this check is usable: what it walks into, and who
 * it says has to fix each finding. Both are invisible from the command's exit
 * code, which is exactly why they are pinned here.
 */
final class InlineScriptSweepTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/csp-sweep-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    #[Test]
    public function aPackageEmissionBlocksAndAnApplicationOneDoesNot(): void
    {
        $this->write('packages/semitexa-thing/src/Page.php', "echo '<script>go()</script>';");
        $this->write('src/modules/Console/templates/page.html.twig', '<script>go()</script>');

        $findings = $this->sweep();

        self::assertCount(2, $findings);
        self::assertSame(InlineScriptOwner::Framework, $findings[0]->owner);
        self::assertSame('packages/semitexa-thing/src/Page.php', $findings[0]->path);
        self::assertSame(InlineScriptOwner::Application, $findings[1]->owner);
    }

    #[Test]
    public function itNeverWalksIntoWhatTheBrowserNeverSees(): void
    {
        $this->write('packages/semitexa-thing/vendor/lib/Page.php', '<script>go()</script>');
        $this->write('packages/semitexa-thing/node_modules/x/index.php', '<script>go()</script>');
        $this->write('packages/semitexa-thing/tests/PageTest.php', "self::assertSame('<script>go()</script>', \$html);");
        $this->write('packages/semitexa-thing/src/Page.js', '<script>go()</script>');

        self::assertSame([], $this->sweep(), 'vendor, node_modules, tests and non-markup files are not emissions');
    }

    #[Test]
    public function anUppercaseEmissionIsNotSkippedByThePrefilter(): void
    {
        // The prefilter is there to avoid scanning every file. It read
        // `<script` case-sensitively while the rule it guards is
        // case-insensitive, so this file was dropped before the scanner saw
        // it and the sweep reported a clean tree.
        $this->write('packages/semitexa-thing/src/Page.php', "echo '<SCRIPT>go()</SCRIPT>';");

        self::assertCount(1, $this->sweep());
    }

    #[Test]
    public function aFileThatStampsItsOwnOutputIsNotAFinding(): void
    {
        // The OS apps are written as one nowdoc and nonce the finished
        // document. The tags in the source are bare and correct.
        $this->write(
            'packages/semitexa-os/src/NotesAppHandler.php',
            "<?php\n\$html = <<<'HTML'\n<script>go()</script>\nHTML;\nreturn \$r->setContent(CspNonce::stamp(\$html));"
        );

        self::assertSame([], $this->sweep());
    }

    #[Test]
    public function talkingAboutTheStamperIsNotCallingIt(): void
    {
        // The exemption used to be str_contains, so a file that only MENTIONS
        // the stamper — in a docblock explaining the rule, most likely — went
        // unscanned. A silent hole in a check whose whole argument is that
        // nothing else can see the defect.
        $this->write(
            'packages/semitexa-os/src/NotesAppHandler.php',
            "<?php\n// Pages like this one should use CspNonce::stamp(\$html) one day.\necho '<script>go()</script>';"
        );

        self::assertCount(1, $this->sweep());
    }

    #[Test]
    public function anExemptionNeedsToBeWrittenDownButIsHonoured(): void
    {
        $this->write(
            'packages/semitexa-os/resources/launcher.html',
            "<!-- csp-nonce-exempt: served statically by the bridge, no response to carry a nonce -->\n<script>go()</script>"
        );

        self::assertSame([], $this->sweep());
    }

    #[Test]
    public function findingsComeBackInFileAndLineOrder(): void
    {
        $this->write('packages/semitexa-b/src/Two.php', "<script>a()</script>\n<script>b()</script>");
        $this->write('packages/semitexa-a/src/One.php', '<script>c()</script>');

        $findings = $this->sweep();

        self::assertSame(
            ['packages/semitexa-a/src/One.php:1', 'packages/semitexa-b/src/Two.php:1', 'packages/semitexa-b/src/Two.php:2'],
            array_map(static fn ($f): string => $f->path . ':' . $f->line, $findings),
        );
    }

    #[Test]
    public function aMissingRootIsSkippedRatherThanFatal(): void
    {
        // A consumer has no packages/ directory at all. The check still runs
        // over what exists, because a linter that dies on a normal project
        // layout is one nobody adds to their pipeline.
        $this->write('src/modules/Console/page.html.twig', '<script>go()</script>');

        $findings = (new InlineScriptSweep())->sweep($this->root, [
            $this->root . '/packages',
            $this->root . '/src',
        ]);

        self::assertCount(1, $findings);
    }

    /** @return list<\Semitexa\Core\Http\InlineScriptFinding> */
    #[Test]
    public function aMarkupExtensionTheScannerReadsIsAlsoOneTheSweepWalksInto(): void
    {
        // The two lists have to agree. The scanner classifies .htm as markup,
        // the traversal did not list it, and the file was rejected before
        // anything read it — so the lint reported a clean tree for a page
        // carrying a bare inline script.
        $this->write('packages/semitexa-thing/src/page.htm', '<script>go()</script>');

        self::assertCount(1, $this->sweep());
    }

    #[Test]
    public function aCallerThatKnowsTheOwnerSaysSoInsteadOfLettingThePathGuess(): void
    {
        // The path rule reads `packages/semitexa-` and `vendor/semitexa/`,
        // and outside those it cannot tell a framework tree from an
        // application one: a package checked out ALONE has its code at
        // `src/…`, exactly where a consumer's own code lives. Filed as
        // Application it is reported and not blocking, so the framework's own
        // emission stops failing anything — which is how the standalone
        // checkout of every package repository was swept for nothing.
        $this->write('src/Http/Page.php', "echo '<script>go()</script>';");

        $guessed = (new InlineScriptSweep())->sweep($this->root, [$this->root . '/src']);
        $told = (new InlineScriptSweep())->sweep($this->root, [$this->root . '/src'], InlineScriptOwner::Framework);

        self::assertSame(InlineScriptOwner::Application, $guessed[0]->owner, 'a bare src/ is a consumer tree by default');
        self::assertSame(InlineScriptOwner::Framework, $told[0]->owner);
    }

    #[Test]
    public function anEligibleFileItCannotReadFailsTheSweep(): void
    {
        // A lint that suppresses the read error and moves on reports a clean
        // tree for a file it never opened — the same silence the check exists
        // to remove, now inside the check itself.
        $this->write('packages/semitexa-thing/src/Page.php', "echo '<script>go()</script>';");
        chmod($this->root . '/packages/semitexa-thing/src/Page.php', 0o000);

        if (is_readable($this->root . '/packages/semitexa-thing/src/Page.php')) {
            self::markTestSkipped('Running as a user that reads mode-000 files; the unreadable case cannot be staged.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/packages\/semitexa-thing\/src\/Page\.php/');

        $this->sweep();
    }

    private function sweep(): array
    {
        return (new InlineScriptSweep())->sweep($this->root, [$this->root . '/packages', $this->root . '/src']);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
