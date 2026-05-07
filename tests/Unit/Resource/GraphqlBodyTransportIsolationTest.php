<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5d: body parser + 4 new exception files must stay pure.
 * Static source check: no DB / ORM / HTTP / renderer / IriBuilder
 * imports, no eval(), no PHP error suppression on json_decode (we want
 * PHP to surface anomalies).
 */
final class GraphqlBodyTransportIsolationTest extends TestCase
{
    #[Test]
    public function body_transport_files_have_no_io_or_runtime_render_imports(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/GraphqlBodyParser.php',
            __DIR__ . '/../../../src/Resource/Exception/MalformedGraphqlRequestBodyException.php',
            __DIR__ . '/../../../src/Resource/Exception/MissingGraphqlQueryException.php',
            __DIR__ . '/../../../src/Resource/Exception/InvalidGraphqlQueryException.php',
            __DIR__ . '/../../../src/Resource/Exception/UnsupportedGraphqlRequestBodyException.php',
        ];

        $forbidden = [
            'use PDO',
            'use Doctrine\\',
            'use Semitexa\\Orm\\',
            'use GuzzleHttp\\',
            'use Psr\\Http\\Client\\',
            'use Semitexa\\Core\\Resource\\IriBuilder;',
            'use Semitexa\\Core\\Resource\\JsonResourceRenderer;',
            'use Semitexa\\Core\\Resource\\JsonLdResourceRenderer;',
            'use Semitexa\\Core\\Resource\\GraphqlResourceRenderer;',
            'use Semitexa\\Core\\Resource\\GraphqlSelectionParser;',
            'use Semitexa\\Core\\Resource\\GraphqlSelectionToIncludeSet;',
            'eval(',
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 5d file %s must not contain %s', basename($path), $needle),
                );
            }
        }
    }
}
