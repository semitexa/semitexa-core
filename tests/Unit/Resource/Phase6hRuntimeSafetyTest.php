<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6h runtime-safety guards. The collection response API must
 * NOT loosen existing invariants:
 *
 *   - `ResourceExpansionPipeline` remains the only Resource-layer
 *     class that calls `->resolveBatch(`.
 *   - Collection responses (`withResources()` on JSON / JSON-LD /
 *     GraphQL) call `expandMany()` exactly once and never call
 *     `->resolveBatch(` directly.
 *   - Renderers still never call `->resolveBatch(`.
 */
final class Phase6hRuntimeSafetyTest extends TestCase
{
    #[Test]
    public function only_pipeline_invokes_resolve_batch_in_resource_layer(): void
    {
        $resourceDir = realpath(__DIR__ . '/../../../src/Resource');
        self::assertNotFalse($resourceDir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        $callers = [];
        foreach ($iterator as $entry) {
            if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }
            $stripped = $this->stripComments((string) file_get_contents($entry->getPathname()));
            if (str_contains($stripped, '->resolveBatch(')) {
                $callers[] = $entry->getPathname();
            }
        }

        self::assertCount(
            1,
            $callers,
            'Exactly one Resource-layer file may invoke ->resolveBatch(); found: ' . implode(', ', $callers),
        );
        self::assertSame('ResourceExpansionPipeline.php', basename($callers[0]));
    }

    #[Test]
    public function collection_responses_do_not_call_resolve_batch_directly(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/JsonResourceResponse.php',
            __DIR__ . '/../../../src/Resource/JsonLdResourceResponse.php',
            __DIR__ . '/../../../src/Resource/GraphqlResourceResponse.php',
        ];

        foreach ($sources as $relPath) {
            $abs = realpath($relPath);
            self::assertNotFalse($abs, "Cannot read {$relPath}");
            $stripped = $this->stripComments((string) file_get_contents($abs));
            self::assertStringNotContainsString(
                '->resolveBatch(',
                $stripped,
                basename($abs) . ' must delegate batch expansion to ResourceExpansionPipeline.',
            );
        }
    }

    #[Test]
    public function collection_responses_call_expand_many_exactly_once(): void
    {
        // The `withResources()` body in each response calls
        // `expandMany()` once and only once. A regression that
        // accidentally introduced a per-parent loop would push the
        // count above 1.
        $sources = [
            __DIR__ . '/../../../src/Resource/JsonResourceResponse.php',
            __DIR__ . '/../../../src/Resource/JsonLdResourceResponse.php',
            __DIR__ . '/../../../src/Resource/GraphqlResourceResponse.php',
        ];

        foreach ($sources as $relPath) {
            $abs = realpath($relPath);
            self::assertNotFalse($abs);
            $stripped = $this->stripComments((string) file_get_contents($abs));
            self::assertSame(
                1,
                substr_count($stripped, '->expandMany('),
                basename($abs) . ' must call expandMany() exactly once.',
            );
        }
    }

    #[Test]
    public function pipeline_source_does_not_reference_db_orm_http_renderer(): void
    {
        $forbidden = [
            'PDO',
            'Doctrine\\',
            'Semitexa\\Orm\\',
            'curl_',
            'Guzzle',
            'IriBuilder',
            'Semitexa\\Core\\Request',
            'JsonResourceRenderer',
            'JsonLdResourceRenderer',
            'GraphqlResourceRenderer',
        ];

        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php'),
        ));

        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $stripped,
                'ResourceExpansionPipeline must not reference `' . $needle . '`.',
            );
        }
    }

    #[Test]
    public function include_validator_still_does_not_instantiate_resolvers(): void
    {
        $stripped = $this->stripComments((string) file_get_contents(
            (string) realpath(__DIR__ . '/../../../src/Resource/IncludeValidator.php'),
        ));
        self::assertStringNotContainsString('->resolveBatch(', $stripped);
        self::assertStringNotContainsString('new RelationResolverInterface', $stripped);
    }

    private function stripComments(string $raw): string
    {
        $tokens   = \PhpToken::tokenize($raw);
        $stripped = '';
        foreach ($tokens as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $stripped .= $token->text;
        }
        return $stripped;
    }
}
