<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6c runtime-safety guards.
 *
 * The Phase 6c include-satisfiability validator is **metadata-only**.
 * It must not:
 *
 *   - instantiate `RelationResolverInterface` implementations,
 *   - invoke `RelationResolverInterface::resolveBatch()`,
 *   - touch the database (PDO / Doctrine / Semitexa ORM),
 *   - issue HTTP calls,
 *   - construct any renderer (`JsonResourceRenderer`,
 *     `JsonLdResourceRenderer`, `GraphqlResourceRenderer`),
 *   - construct an `IriBuilder`,
 *   - mutate `ResourceMetadataRegistry`,
 *   - read `Request`.
 *
 * These tests are static source-content checks — the same pattern used
 * by `OpenApiIsolationTest` (Phase 5b). Static checks are stronger than
 * mock-based assertions because they fail at CI time even without a
 * test runner walking the production path.
 */
final class Phase6cRuntimeSafetyTest extends TestCase
{
    private const FORBIDDEN_TOKENS_IN_INCLUDE_VALIDATOR = [
        '->resolveBatch(',
        'PDO',
        'Doctrine\\',
        'Semitexa\\Orm\\',
        'JsonResourceRenderer',
        'JsonLdResourceRenderer',
        'GraphqlResourceRenderer',
        'IriBuilder',
        'Semitexa\\Core\\Request',
        'curl_',
        'Guzzle',
    ];

    private const FORBIDDEN_TOKENS_IN_HANDLER_PROVIDED_VALIDATOR = [
        '->resolveBatch(',
        'PDO',
        'Doctrine\\',
        'Semitexa\\Orm\\',
        'JsonResourceRenderer',
        'JsonLdResourceRenderer',
        'GraphqlResourceRenderer',
        'IriBuilder',
        'Semitexa\\Core\\Request',
        '->register(',
    ];

    private const FORBIDDEN_TOKENS_IN_HANDLER_PROVIDED_REGISTRY = [
        '->resolveBatch(',
        'PDO',
        'Doctrine\\',
        'Semitexa\\Orm\\',
        'IriBuilder',
        'Semitexa\\Core\\Request',
        'JsonResourceRenderer',
        'JsonLdResourceRenderer',
        'GraphqlResourceRenderer',
    ];

    #[Test]
    public function include_validator_source_is_pure(): void
    {
        $source = $this->fileContents(
            __DIR__ . '/../../../src/Resource/IncludeValidator.php',
        );

        foreach (self::FORBIDDEN_TOKENS_IN_INCLUDE_VALIDATOR as $token) {
            self::assertStringNotContainsString(
                $token,
                $source,
                "IncludeValidator must not reference `{$token}`. Phase 6c is metadata-only.",
            );
        }
    }

    #[Test]
    public function handler_provided_include_validator_source_is_pure(): void
    {
        $source = $this->fileContents(
            __DIR__ . '/../../../src/Resource/HandlerProvidedIncludeValidator.php',
        );

        foreach (self::FORBIDDEN_TOKENS_IN_HANDLER_PROVIDED_VALIDATOR as $token) {
            self::assertStringNotContainsString(
                $token,
                $source,
                "HandlerProvidedIncludeValidator must not reference `{$token}`. Phase 6c is metadata-only.",
            );
        }
    }

    #[Test]
    public function handler_provided_include_registry_source_is_pure(): void
    {
        $source = $this->fileContents(
            __DIR__ . '/../../../src/Resource/HandlerProvidedIncludeRegistry.php',
        );

        foreach (self::FORBIDDEN_TOKENS_IN_HANDLER_PROVIDED_REGISTRY as $token) {
            self::assertStringNotContainsString(
                $token,
                $source,
                "HandlerProvidedIncludeRegistry must not reference `{$token}`. Phase 6c is metadata-only.",
            );
        }
    }

    #[Test]
    public function unsatisfied_include_exception_extends_domain_exception_with_400(): void
    {
        $reflection = new \ReflectionClass(
            \Semitexa\Core\Resource\Exception\UnsatisfiedResourceIncludeException::class,
        );

        self::assertSame(
            \Semitexa\Core\Exception\DomainException::class,
            $reflection->getParentClass()?->getName(),
            'Phase 6c exception must extend Semitexa\\Core\\Exception\\DomainException so the existing ExceptionMapper picks it up.',
        );

        // Construct one to verify status code mapping (no I/O involved).
        $instance = new \Semitexa\Core\Resource\Exception\UnsatisfiedResourceIncludeException(
            resourceType: 'x',
            token: 'y',
            relationName: 'y',
            resolverMissing: true,
            handlerContractMissing: true,
        );
        self::assertSame(400, $instance->getStatusCode()->value);
    }

    /**
     * Read a PHP source file with all comments stripped, so doc-comment
     * mentions of forbidden tokens (which describe what the file *does
     * not* do) don't produce false positives.
     */
    private function fileContents(string $relativePath): string
    {
        $absolute = realpath($relativePath);
        self::assertNotFalse($absolute, "Source file not found: {$relativePath}");
        $raw = file_get_contents($absolute);
        self::assertNotFalse($raw, "Could not read source file: {$absolute}");

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
