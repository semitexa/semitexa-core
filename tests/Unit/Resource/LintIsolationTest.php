<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceMetadataValidator;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\BotResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CommentResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\PreferencesResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\UserResource;

/**
 * Asserts that the metadata pipeline does not require — and does not perform —
 * any HTTP, DB, ORM, or container-managed services. Phase 1 must be safe to run
 * before the worker is fully booted.
 */
final class LintIsolationTest extends TestCase
{
    #[Test]
    public function pipeline_runs_without_container_or_http_or_db(): void
    {
        // No container. No request. No PDO. No psr/http-message instance.
        // The whole pipeline is reflection + arrays.
        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        foreach ([
            AddressResource::class,
            PreferencesResource::class,
            ProfileResource::class,
            CustomerResource::class,
            UserResource::class,
            BotResource::class,
            CommentResource::class,
        ] as $class) {
            $registry->register($extractor->extract($class));
        }

        $validator = ResourceMetadataValidator::forTesting($registry);
        $errors    = $validator->validate();

        self::assertSame([], array_map(fn ($e) => $e->getMessage(), $errors));
        self::assertCount(7, $registry->all());
    }

    #[Test]
    public function classes_used_by_the_pipeline_have_no_io_dependencies(): void
    {
        // Sanity check: the Resource pipeline classes do not import Doctrine, PDO, Guzzle, etc.
        $sources = [
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataExtractor.php',
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataRegistry.php',
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataValidator.php',
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataCacheFile.php',
            __DIR__ . '/../../../src/Application/Console/Command/LintResourcesCommand.php',
        ];

        $forbidden = [
            'use PDO',
            'Doctrine\\',
            'GuzzleHttp\\',
            'Symfony\\Component\\HttpFoundation\\Request',
            'Psr\\Http\\Message\\',
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 1 file %s must not import %s', basename($path), $needle),
                );
            }
        }
    }
}
