<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile;

/**
 * Phase 6m: consolidated release-readiness guard. The per-phase
 * runtime-safety tests already pin every individual invariant; this
 * test ties them into one closure-style sweep that any future
 * refactor must keep green for the Resource DTO v1 contract to
 * remain intact.
 *
 * Each assertion below maps to a contract clause in the
 * "Resource DTO v1 Status" section of
 * `var/docs/resource-dto-relation-contract-design.md`.
 */
final class Phase6mV1ReleaseSafetyTest extends TestCase
{
    private const PIPELINE_FORBIDDEN_TOKENS = [
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

    private const PURE_VALUE_OBJECTS = [
        // Phase 6i pagination.
        'Pagination/CollectionPageRequest.php',
        'Pagination/CollectionPage.php',
        // Phase 6j sorting.
        'Sort/CollectionSortRequest.php',
        'Sort/SortDirection.php',
        'Sort/SortTerm.php',
        // Phase 6k filtering.
        'Filter/CollectionFilterRequest.php',
        'Filter/FilterOperator.php',
        'Filter/FilterTerm.php',
        // Phase 6l cursor pagination.
        'Cursor/CollectionCursor.php',
        'Cursor/CollectionCursorCodec.php',
        'Cursor/CollectionCursorPage.php',
    ];

    /**
     * Closure clause #1: only `ResourceExpansionPipeline` invokes
     * `RelationResolverInterface::resolveBatch()`. Renderers and
     * validators must stay metadata-only.
     */
    #[Test]
    public function only_pipeline_invokes_resolve_batch_anywhere_in_the_resource_layer(): void
    {
        $resourceDir = (string) realpath(__DIR__ . '/../../../src/Resource');
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
        self::assertCount(1, $callers, 'exactly one Resource-layer file may invoke ->resolveBatch()');
        self::assertSame('ResourceExpansionPipeline.php', basename($callers[0]));
    }

    /**
     * Closure clause #2: `ResourceExpansionPipeline` source is free
     * of DB / ORM / HTTP / IriBuilder / renderer references.
     */
    #[Test]
    public function pipeline_source_does_not_reference_runtime_io_or_renderers(): void
    {
        $stripped = $this->stripCommentsFromFile(
            __DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php',
        );
        foreach (self::PIPELINE_FORBIDDEN_TOKENS as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $stripped,
                "ResourceExpansionPipeline must not reference `{$needle}`.",
            );
        }
    }

    /**
     * Closure clause #3: every collection-shaped value object
     * (page / sort / filter / cursor) is a pure data structure —
     * no DB, ORM, HTTP, IriBuilder, renderer, resolver, or pipeline
     * references.
     */
    #[Test]
    public function every_collection_value_object_is_pure(): void
    {
        $forbidden = [
            ...self::PIPELINE_FORBIDDEN_TOKENS,
            '->resolveBatch(',
            '->expandMany(',
        ];
        foreach (self::PURE_VALUE_OBJECTS as $rel) {
            $stripped = $this->stripCommentsFromFile(
                __DIR__ . '/../../../src/Resource/' . $rel,
            );
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $stripped,
                    basename($rel) . " must not reference `{$needle}`.",
                );
            }
        }
    }

    /**
     * Closure clause #4: collection responses delegate batching.
     * Each `withResources()` body calls `expandMany()` exactly once
     * and never touches `resolveBatch()` directly.
     */
    #[Test]
    public function each_collection_response_delegates_to_expand_many_exactly_once(): void
    {
        foreach ([
            'JsonResourceResponse.php',
            'JsonLdResourceResponse.php',
            'GraphqlResourceResponse.php',
        ] as $name) {
            $stripped = $this->stripCommentsFromFile(
                __DIR__ . '/../../../src/Resource/' . $name,
            );
            self::assertSame(
                1,
                substr_count($stripped, '->expandMany('),
                "{$name} must call expandMany() exactly once.",
            );
            self::assertStringNotContainsString(
                '->resolveBatch(',
                $stripped,
                "{$name} must not call ->resolveBatch() directly.",
            );
        }
    }

    /**
     * Closure clause #5: validators never instantiate resolvers.
     */
    #[Test]
    public function include_validators_remain_metadata_only(): void
    {
        foreach ([
            'IncludeValidator.php',
            'HandlerProvidedIncludeValidator.php',
        ] as $name) {
            $stripped = $this->stripCommentsFromFile(
                __DIR__ . '/../../../src/Resource/' . $name,
            );
            self::assertStringNotContainsString('->resolveBatch(', $stripped);
            self::assertStringNotContainsString('new RelationResolverInterface', $stripped);
        }
    }

    /**
     * Closure clause #6: metadata extraction does not instantiate
     * resolvers and does not access DB / ORM / HTTP. The cache file
     * also stays IO-bounded to file-system only.
     */
    #[Test]
    public function metadata_layer_remains_pure_at_extraction_time(): void
    {
        foreach ([
            'Metadata/ResourceMetadataExtractor.php',
            'Metadata/ResourceMetadataValidator.php',
            'Metadata/ResourceMetadataRegistry.php',
            'Metadata/ResourceMetadataSourceFingerprint.php',
            'Metadata/ResourceMetadataCacheFile.php',
        ] as $rel) {
            $stripped = $this->stripCommentsFromFile(
                __DIR__ . '/../../../src/Resource/' . $rel,
            );
            self::assertStringNotContainsString('->resolveBatch(', $stripped);
            self::assertStringNotContainsString('new RelationResolverInterface', $stripped);
            // No DB / ORM / HTTP-client references in any metadata
            // layer file.
            foreach (['PDO', 'Doctrine\\', 'Semitexa\\Orm\\', 'curl_', 'Guzzle'] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $stripped,
                    basename($rel) . " must not reference `{$needle}`.",
                );
            }
        }
    }

    /**
     * Closure clause #7: the deprecated boolean
     * `ResourceMetadataCacheFile::load()` shim was removed for the
     * v1 release. Its callers either migrated to
     * `loadWithResult()` or were deleted. This test pins the
     * removal so a re-introduction surfaces immediately.
     */
    #[Test]
    public function deprecated_load_shim_is_removed(): void
    {
        $ref = new \ReflectionClass(ResourceMetadataCacheFile::class);
        self::assertFalse(
            $ref->hasMethod('load'),
            'ResourceMetadataCacheFile::load() must remain removed for the v1 contract; '
            . 'use loadWithResult() and CacheLoadResult to distinguish Hit / Miss / Stale / Corrupt.',
        );
        self::assertTrue($ref->hasMethod('loadWithResult'));
        self::assertTrue($ref->hasMethod('dump'));
    }

    /**
     * Closure clause #9: no request-global resolver state leaks
     * between calls — `ResolvedResourceGraph` is `final readonly`
     * and pipeline source has no static caches.
     */
    #[Test]
    public function pipeline_keeps_no_static_resolver_state(): void
    {
        $pipelineRef = new \ReflectionClass(\Semitexa\Core\Resource\ResourceExpansionPipeline::class);
        foreach ($pipelineRef->getProperties() as $prop) {
            self::assertFalse(
                $prop->isStatic(),
                "ResourceExpansionPipeline must not hold static state; "
                . "found static \${$prop->getName()}.",
            );
        }

        $graphRef = new \ReflectionClass(\Semitexa\Core\Resource\ResolvedResourceGraph::class);
        self::assertTrue(
            $graphRef->isReadOnly(),
            'ResolvedResourceGraph must remain `final readonly` to prevent '
            . 'cross-request mutation of resolved overlay state.',
        );
        self::assertTrue($graphRef->isFinal());
    }

    private function stripCommentsFromFile(string $relativePath): string
    {
        $absolute = realpath($relativePath);
        self::assertNotFalse($absolute, "Source file not found: {$relativePath}");
        return $this->stripComments((string) file_get_contents($absolute));
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
