<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6d runtime-safety guards.
 *
 * `ResourceExpansionPipeline` is the **only** class that calls
 * `RelationResolverInterface::resolveBatch()`. Renderers stay pure;
 * `IncludeValidator` and `HandlerProvidedIncludeValidator` stay
 * metadata-only (no resolver instantiation, no DB / ORM / HTTP).
 *
 * Static source-content checks (PHP comments stripped) are stronger
 * than runtime mock counters because they fail at CI time even
 * without a request walking through the production code path.
 */
final class Phase6dRuntimeSafetyTest extends TestCase
{
    private const RENDERER_FORBIDDEN = [
        '->resolveBatch(',
        'PDO',
        'Doctrine\\',
        'Semitexa\\Orm\\',
        'curl_',
        'Guzzle',
        'IriBuilder',
        'Semitexa\\Core\\Request',
    ];

    private const VALIDATOR_FORBIDDEN = [
        '->resolveBatch(',
        'new RelationResolverInterface',
        '$resolverClass(',
        'PDO',
        'Doctrine\\',
        'Semitexa\\Orm\\',
        'curl_',
    ];

    private const PIPELINE_FORBIDDEN = [
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

    #[Test]
    public function json_renderer_does_not_call_resolve_batch(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/JsonResourceRenderer.php',
            self::RENDERER_FORBIDDEN,
        );
    }

    #[Test]
    public function jsonld_renderer_does_not_call_resolve_batch(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/JsonLdResourceRenderer.php',
            self::RENDERER_FORBIDDEN,
        );
    }

    #[Test]
    public function graphql_renderer_does_not_call_resolve_batch(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/GraphqlResourceRenderer.php',
            self::RENDERER_FORBIDDEN,
        );
    }

    #[Test]
    public function include_validator_does_not_call_resolve_batch_or_instantiate_resolvers(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/IncludeValidator.php',
            self::VALIDATOR_FORBIDDEN,
        );
    }

    #[Test]
    public function handler_provided_include_validator_stays_metadata_only(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/HandlerProvidedIncludeValidator.php',
            self::VALIDATOR_FORBIDDEN,
        );
    }

    #[Test]
    public function expansion_pipeline_does_not_touch_db_or_renderers(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/ResourceExpansionPipeline.php',
            self::PIPELINE_FORBIDDEN,
        );
    }

    #[Test]
    public function resolved_resource_graph_is_pure_data(): void
    {
        $this->assertSourcePure(
            __DIR__ . '/../../../src/Resource/ResolvedResourceGraph.php',
            ['->resolveBatch(', 'PDO', 'Doctrine\\', 'Semitexa\\Orm\\', 'curl_', 'JsonResourceRenderer', 'IriBuilder'],
        );
    }

    /**
     * @param list<string> $forbiddenTokens
     */
    private function assertSourcePure(string $relativePath, array $forbiddenTokens): void
    {
        $absolute = realpath($relativePath);
        self::assertNotFalse($absolute, "Source file not found: {$relativePath}");
        $raw = file_get_contents($absolute);
        self::assertNotFalse($raw);

        $tokens   = \PhpToken::tokenize($raw);
        $stripped = '';
        foreach ($tokens as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            $stripped .= $token->text;
        }

        foreach ($forbiddenTokens as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $stripped,
                basename($absolute) . ' must not reference `' . $forbidden . '`.',
            );
        }
    }
}
