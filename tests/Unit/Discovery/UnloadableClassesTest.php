<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;

/**
 * An attribute scan says which classes it could not inspect
 * (tk-discovery-reports-dropped-classes). A class whose file failed to load
 * was left out of findClassesWithAttribute() with at most a boot diagnostic,
 * which no console command reads: orm:sync finished green while a
 * #[FromTable] resource was missing from the schema. Two of the drops
 * recorded nothing at all: a class map entry whose file is gone, and one
 * whose file declares another class.
 */
final class UnloadableClassesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        ClassDiscovery::resetSharedCache();
        $this->dir = sys_get_temp_dir() . '/semitexa-unloadable-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        ClassDiscovery::resetSharedCache();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function every_class_a_scan_could_not_inspect_is_named_with_why(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $broken = "Unloadable{$suffix}\\Broken";
        $elsewhere = "Unloadable{$suffix}\\Elsewhere";
        $gone = "Unloadable{$suffix}\\Gone";
        file_put_contents($this->dir . '/Broken.php', "<?php\nnamespace Unloadable{$suffix};\nfinal class Broken extends Missing{$suffix} {}\n");
        file_put_contents($this->dir . '/Elsewhere.php', "<?php\nnamespace Unloadable{$suffix};\nfinal class SomethingElse {}\n");
        self::seedSharedClassMap([
            $broken => $this->dir . '/Broken.php',
            $elsewhere => $this->dir . '/Elsewhere.php',
            $gone => $this->dir . '/Gone.php',
        ]);

        $discovery = new ClassDiscovery();
        self::assertSame([], $discovery->findClassesWithAttribute(AsService::class), 'none of them could be inspected');

        $dropped = $discovery->unloadableClasses();
        self::assertSame([$broken, $elsewhere, $gone], array_keys($dropped));
        self::assertStringStartsWith('load error: ', $dropped[$broken]);
        self::assertStringContainsString("Missing{$suffix}", $dropped[$broken]);
        self::assertSame($this->dir . '/Elsewhere.php does not declare it', $dropped[$elsewhere]);
        self::assertSame($this->dir . '/Gone.php does not exist', $dropped[$gone]);

        // An instance served from the shared answer still hears what it left out.
        self::assertSame($dropped, (new ClassDiscovery())->unloadableClasses());
    }

    /** @param array<string, string> $classMap */
    private static function seedSharedClassMap(array $classMap): void
    {
        $property = new ReflectionProperty(ClassDiscovery::class, 'sharedClassMaps');
        $property->setValue(null, [ProjectRoot::get() => $classMap]);
    }
}
