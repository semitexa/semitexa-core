<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6e runtime-safety guards.
 *
 * The framework guarantees from Phase 6d (no resolveBatch in any
 * renderer or validator, only in `ResourceExpansionPipeline`) are
 * re-asserted here so a Phase 6e regression flips the alarm.
 *
 * Static source-content checks (PHP comments stripped) are stronger
 * than runtime mock counters because they fail at CI time even
 * without a request walking through the production code path.
 */
final class Phase6eRuntimeSafetyTest extends TestCase
{
    #[Test]
    public function pipeline_is_still_the_only_resolve_batch_caller_in_resource_layer(): void
    {
        // Walk every PHP file under packages/semitexa-core/src/Resource/
        // and assert that ONLY ResourceExpansionPipeline.php contains
        // `->resolveBatch(`.
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

        self::assertCount(1, $callers, 'Exactly one Resource-layer file may invoke ->resolveBatch(); found: ' . implode(', ', $callers));
        self::assertSame(
            'ResourceExpansionPipeline.php',
            basename($callers[0]),
            'Only ResourceExpansionPipeline may invoke resolveBatch().',
        );
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
