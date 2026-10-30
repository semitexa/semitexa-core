<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Stability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What semitexa/core promises (docs: get-started/stability): the symbols
 * marked @api, and only those. The list is pinned so the promise grows only
 * on purpose: a mark added or dropped shows here, in the same review as the
 * code. A dropped one is a break, owed a 90-day deprecation first.
 */
final class ApiSurfaceTest extends TestCase
{
    #[Test]
    public function the_api_surface_is_the_one_promised(): void
    {
        self::assertSame([
            'Semitexa\Core\Attribute\AsCommand',
            'Semitexa\Core\Attribute\AsEvent',
            'Semitexa\Core\Attribute\AsEventListener',
            'Semitexa\Core\Attribute\AsPayloadHandler',
            'Semitexa\Core\Attribute\AsPipelineListener',
            'Semitexa\Core\Attribute\AsPublicPayload',
            'Semitexa\Core\Attribute\AsServerLifecycleListener',
            'Semitexa\Core\Attribute\AsService',
            'Semitexa\Core\Attribute\AsWorkerProcessSource',
            'Semitexa\Core\Attribute\Config',
            'Semitexa\Core\Attribute\ExecutionScoped',
            'Semitexa\Core\Attribute\InjectAsFactory',
            'Semitexa\Core\Attribute\InjectAsMutable',
            'Semitexa\Core\Attribute\InjectAsReadonly',
            'Semitexa\Core\Attribute\SatisfiesRepositoryContract',
            'Semitexa\Core\Attribute\SatisfiesServiceContract',
            'Semitexa\Core\Attribute\TransportType',
            'Semitexa\Core\Contract\TypedHandlerInterface',
            'Semitexa\Core\Contract\ValidatablePayloadInterface',
        ], self::marked());
    }

    /** @return list<string> every class-like in src/ whose docblock opens a line with @api */
    private static function marked(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // The class docblock: the last one before the declaration, attributes between allowed.
            $pattern = '/\/\*\*((?:(?!\*\/).)*)\*\/\s*(?:#\[[^\n]*\]\s*)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/s';
            if (preg_match_all($pattern, $source, $m, PREG_SET_ORDER) === false) {
                self::fail('cannot read ' . $file->getPathname());
            }
            preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
            foreach ($m as $match) {
                if (preg_match('/^[ \t]*(?:\*[ \t]?|\/\*\*[ \t]*)@api\b/m', '/**' . $match[1]) === 1) {
                    $found[] = ($ns[1] ?? '') . '\\' . $match[2];
                }
            }
        }
        sort($found);

        return $found;
    }
}
