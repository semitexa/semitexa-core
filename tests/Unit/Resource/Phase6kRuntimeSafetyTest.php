<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6k runtime-safety guards. The filter baseline must not
 * weaken the resolver / pipeline invariants:
 *
 *   - Filter source files do not import DB / ORM / HTTP / framework
 *     runtime classes; they are pure value objects + parsers.
 *   - `ResourceExpansionPipeline` is still the only Resource-layer
 *     file that calls `->resolveBatch(`.
 *   - Each collection response calls `->expandMany(` exactly once.
 *   - `IncludeValidator` still does not instantiate resolvers.
 */
final class Phase6kRuntimeSafetyTest extends TestCase
{
    private const FILTER_FORBIDDEN = [
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
        '->resolveBatch(',
        '->expandMany(',
    ];

    #[Test]
    public function collection_filter_request_source_is_pure(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/Filter/CollectionFilterRequest.php',
            self::FILTER_FORBIDDEN,
        );
    }

    #[Test]
    public function filter_term_source_is_pure(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/Filter/FilterTerm.php',
            self::FILTER_FORBIDDEN,
        );
    }

    #[Test]
    public function filter_operator_source_is_pure(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/Filter/FilterOperator.php',
            self::FILTER_FORBIDDEN,
        );
    }

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
            'Only ResourceExpansionPipeline may invoke ->resolveBatch(); found: '
            . implode(', ', $callers),
        );
        self::assertSame('ResourceExpansionPipeline.php', basename($callers[0]));
    }

    #[Test]
    public function collection_responses_still_call_expand_many_exactly_once(): void
    {
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
            self::assertStringNotContainsString(
                '->resolveBatch(',
                $stripped,
                basename($abs) . ' must not call ->resolveBatch() directly.',
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

    /**
     * @param list<string> $forbiddenTokens
     */
    private function assertSourcePure(string $relativePath, array $forbiddenTokens): void
    {
        $absolute = realpath($relativePath);
        self::assertNotFalse($absolute, "Source file not found: {$relativePath}");
        $stripped = $this->stripComments((string) file_get_contents($absolute));

        foreach ($forbiddenTokens as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $stripped,
                basename($absolute) . ' must not reference `' . $forbidden . '`.',
            );
        }
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
