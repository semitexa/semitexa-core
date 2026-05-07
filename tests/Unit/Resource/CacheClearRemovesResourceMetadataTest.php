<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile;

/**
 * Phase 3d guard: confirm that the cache:clear command's source still
 * references the resource-metadata.php cache file. The behavioral test for
 * cache:clear itself lives in semitexa-core's command tests; this test
 * only ensures the eviction wiring isn't accidentally lost.
 */
final class CacheClearRemovesResourceMetadataTest extends TestCase
{
    #[Test]
    public function cache_clear_command_evicts_resource_metadata_file(): void
    {
        $command = file_get_contents(__DIR__ . '/../../../src/Application/Console/Command/CacheClearCommand.php');
        self::assertNotFalse($command);
        self::assertStringContainsString(
            'resource-metadata.php',
            $command,
            'cache:clear must evict var/cache/resource-metadata.php so a stale cache cannot poison the next worker.',
        );
    }

    #[Test]
    public function relative_path_constant_is_canonical(): void
    {
        self::assertSame('var/cache/resource-metadata.php', ResourceMetadataCacheFile::RELATIVE_PATH);
    }
}
