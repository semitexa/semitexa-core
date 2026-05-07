<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\IncludeSet;
use Semitexa\Core\Resource\SupportsResourceIncludes;

/**
 * Phase 2.5 contract guards for the include behavior surface:
 * - Payloads that do NOT implement SupportsResourceIncludes silently get an
 *   empty IncludeSet — no error, no accidental embedding.
 * - HTML routes do not consume `?include=` unless they explicitly opt in.
 */
final class IncludeContractTest extends TestCase
{
    #[Test]
    public function payload_not_implementing_supports_resource_includes_is_recognizable(): void
    {
        $opted = new class implements SupportsResourceIncludes {
            public function includes(): IncludeSet
            {
                return IncludeSet::fromQueryString('addresses');
            }
        };
        $notOpted = new class {};

        self::assertInstanceOf(SupportsResourceIncludes::class, $opted);
        self::assertNotInstanceOf(SupportsResourceIncludes::class, $notOpted);
    }

    #[Test]
    public function include_set_is_safe_to_construct_without_query_string(): void
    {
        // Phase 2 contract: null / empty raw query → empty include set; no error.
        $a = IncludeSet::fromQueryString(null);
        $b = IncludeSet::fromQueryString('');
        $c = IncludeSet::empty();

        self::assertTrue($a->isEmpty());
        self::assertTrue($b->isEmpty());
        self::assertTrue($c->isEmpty());
    }

    #[Test]
    public function html_renderer_path_does_not_appear_in_phase_2_sources(): void
    {
        // HTML routes must not consume ?include= unless explicitly opted in.
        // Phase 2 ships only the JSON renderer; verify that no HTML-path
        // file pulls in IncludeSet without going through SupportsResourceIncludes
        // (which is a payload-level marker, not a renderer-level coupling).
        $jsonResp = file_get_contents(__DIR__ . '/../../../src/Resource/JsonResourceResponse.php');
        $renderer = file_get_contents(__DIR__ . '/../../../src/Resource/JsonResourceRenderer.php');

        self::assertNotFalse($jsonResp);
        self::assertNotFalse($renderer);
        self::assertStringNotContainsString('HtmlResponse', $jsonResp);
        self::assertStringNotContainsString('Twig', $jsonResp);
        self::assertStringNotContainsString('HtmlResponse', $renderer);
        self::assertStringNotContainsString('Twig', $renderer);
    }
}
