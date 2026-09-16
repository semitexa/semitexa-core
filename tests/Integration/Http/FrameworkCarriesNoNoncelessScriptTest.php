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

        if (!is_dir($root . '/packages')) {
            self::markTestSkipped('No packages/ tree here — this is a consumer install, not the workspace.');
        }

        $findings = (new InlineScriptSweep())->sweep($root, [$root . '/packages']);
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
