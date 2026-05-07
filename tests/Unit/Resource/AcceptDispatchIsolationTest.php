<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3e: the Accept resolver and the dispatcher must be pure functions.
 * Static source check confirms neither imports a runtime renderer, IriBuilder,
 * Request, DB, ORM, or HTTP client.
 */
final class AcceptDispatchIsolationTest extends TestCase
{
    #[Test]
    public function accept_dispatch_files_have_no_io_or_runtime_render_imports(): void
    {
        $sources = [
            __DIR__ . '/../../../src/Resource/AcceptHeaderResolver.php',
            __DIR__ . '/../../../src/Resource/CrossProfileDispatcher.php',
            __DIR__ . '/../../../src/Resource/Exception/UnsupportedAcceptHeaderException.php',
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
        ];

        foreach ($sources as $path) {
            $content = file_get_contents($path);
            self::assertNotFalse($content, "Cannot read $path");
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $content,
                    sprintf('Phase 3e file %s must not import %s', basename($path), $needle),
                );
            }
        }
    }
}
