<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5c: parser + bridge + the four exception files must stay pure.
 * Static source check across all selection-set bridge files.
 */
final class GraphqlBridgeIsolationTest extends TestCase
{
    #[Test]
    public function bridge_files_have_no_io_or_runtime_render_imports(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/GraphqlSelectionParser.php',
            __DIR__ . '/../../../src/Resource/GraphqlSelectionNode.php',
            __DIR__ . '/../../../src/Resource/GraphqlSelectionToIncludeSet.php',
            __DIR__ . '/../../../src/Resource/Exception/MalformedGraphqlSelectionException.php',
            __DIR__ . '/../../../src/Resource/Exception/UnsupportedGraphqlFeatureException.php',
            __DIR__ . '/../../../src/Resource/Exception/UnknownGraphqlFieldException.php',
            __DIR__ . '/../../../src/Resource/Exception/GraphqlSelectionDepthExceededException.php',
        ];

        $forbidden = [
            'use PDO',
            'use Doctrine\\',
            'use Semitexa\\Orm\\',
            'use GuzzleHttp\\',
            'use Psr\\Http\\Client\\',
            'use Semitexa\\Core\\Request;',
            'use Semitexa\\Core\\Resource\\IriBuilder;',
            'use Semitexa\\Core\\Resource\\JsonResourceRenderer;',
            'use Semitexa\\Core\\Resource\\JsonLdResourceRenderer;',
            'use Semitexa\\Core\\Resource\\GraphqlResourceRenderer;',
            // The parser must not pull in eval-style helpers either.
            'eval(',
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 5c file %s must not contain %s', basename($path), $needle),
                );
            }
        }
    }
}
