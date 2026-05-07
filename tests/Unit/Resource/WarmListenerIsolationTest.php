<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3d: lifecycle warmup must not import any of: PDO/Doctrine/Orm/Guzzle/
 * Psr-Http/JsonResourceRenderer/IriBuilder/Request. Static source check.
 */
final class WarmListenerIsolationTest extends TestCase
{
    #[Test]
    public function lifecycle_listener_has_no_io_or_runtime_render_imports(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/Lifecycle/WarmResourceMetadataListener.php',
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataRegistry.php',
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataCacheFile.php',
            __DIR__ . '/../../../src/Resource/Metadata/ResourceMetadataSourceFingerprint.php',
            __DIR__ . '/../../../src/Resource/Metadata/CacheLoadResult.php',
        ];

        // Match real *usage* (use-statements, fully-qualified references)
        // rather than any mention of these names — docblocks may legitimately
        // explain "this file does not use X" without smuggling X in.
        $forbidden = [
            'use PDO',
            'use Doctrine\\',
            'use Semitexa\\Orm\\',
            'use GuzzleHttp\\',
            'use Psr\\Http\\Client\\',
            'use Semitexa\\Core\\Resource\\JsonResourceRenderer;',
            'use Semitexa\\Core\\Resource\\IriBuilder;',
            'use Semitexa\\Core\\Request;',
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 3d file %s must not import %s', basename($path), $needle),
                );
            }
        }
    }
}
