<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\InlineScriptOwner;
use Semitexa\Core\Http\InlineScriptSweep;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The framework holds itself to its own rule.
 *
 * The unit tests pin what the scanner calls a finding; this one runs it over
 * the real tree, because the rule is only worth anything if the packages obey
 * it. Before this test existed there were 22 inline scripts across ten
 * packages that a nonce policy would have refused — including the SSR deferred
 * manifest, which made the entire deferred first paint unreachable for any
 * consumer with a CSP, silently.
 *
 * A consumer's own findings are deliberately NOT asserted here: whether an
 * inline script in an application is broken depends on a policy only that
 * project knows about. `lint:inline-script --strict` is where a project with a
 * policy gates on its own.
 */
final class FrameworkCarriesNoNoncelessScriptTest extends TestCase
{
    #[Test]
    public function noPackageEmitsAnInlineScriptWithoutANonce(): void
    {
        $root = ProjectRoot::get();

        // In the workspace the packages are under `packages/`, and the path
        // rule files them as framework on its own. In a package checked out
        // ALONE — which is how each repository is cloned and reviewed — the
        // same code sits at `src/…`, indistinguishable by path from a
        // consumer's own, so this test used to skip and the tree it exists to
        // guard was never swept at all. The owner is passed instead of
        // guessed: here it is known, and it is this repository.
        [$roots, $owner] = is_dir($root . '/packages')
            ? [[$root . '/packages'], null]
            : [[$root . '/src'], InlineScriptOwner::Framework];

        // The root is asserted to EXIST before the sweep. sweep() skips a root
        // that is not there, so without this the final empty-array assertion
        // passes having scanned nothing at all — the framework tree declared
        // clean because it was never opened.
        self::assertDirectoryExists($roots[0], 'the framework tree this test exists to guard must be there to scan');

        $findings = (new InlineScriptSweep())->sweep($root, $roots, $owner);
        $framework = array_values(array_filter(
            $findings,
            static fn ($f): bool => $f->owner === InlineScriptOwner::Framework
        ));

        self::assertSame(
            [],
            array_map(static fn ($f): string => $f->path . ':' . $f->line . ' ' . $f->snippet, $framework),
            'Give the tag a nonce (CspNonce::attribute(), csp_nonce_attr() in Twig, or CspNonce::stamp() '
            . 'on a finished document), or make it data — type="application/json" is not governed by script-src.'
        );
    }
}
