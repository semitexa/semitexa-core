<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Metadata\ResourceMetadataSourceFingerprint;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\AddressResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\CustomerResource;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\InMemoryDiscoveryFor3d5;
use Semitexa\Core\Tests\Unit\Resource\Fixtures\ProfileResource;

/**
 * Phase 3d.5: deterministic source fingerprint for stale-cache protection.
 */
final class SourceFingerprintTest extends TestCase
{
    private function discovery(array $classes): ClassDiscovery
    {
        return new InMemoryDiscoveryFor3d5($classes);
    }

    #[Test]
    public function deterministic_for_same_class_set(): void
    {
        $f = ResourceMetadataSourceFingerprint::forTesting(
            $this->discovery([AddressResource::class, ProfileResource::class, CustomerResource::class]),
        );

        $a = $f->compute();
        $b = $f->compute();
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a), 'sha256 hex digest is 64 chars.');
    }

    #[Test]
    public function reordered_discovery_input_yields_same_fingerprint(): void
    {
        $a = ResourceMetadataSourceFingerprint::forTesting(
            $this->discovery([AddressResource::class, ProfileResource::class, CustomerResource::class]),
        )->compute();

        $b = ResourceMetadataSourceFingerprint::forTesting(
            $this->discovery([CustomerResource::class, AddressResource::class, ProfileResource::class]),
        )->compute();

        self::assertSame($a, $b, 'Fingerprint must sort the class list before hashing.');
    }

    #[Test]
    public function adding_or_removing_a_class_changes_fingerprint(): void
    {
        $small = ResourceMetadataSourceFingerprint::forTesting(
            $this->discovery([AddressResource::class]),
        )->compute();

        $big = ResourceMetadataSourceFingerprint::forTesting(
            $this->discovery([AddressResource::class, ProfileResource::class]),
        )->compute();

        self::assertNotSame($small, $big);
    }

    #[Test]
    public function changing_source_file_content_changes_fingerprint(): void
    {
        // Stage a temporary fixture file, fingerprint it, then mutate the
        // file and confirm the fingerprint flips. This proves the SHA-256
        // includes file content, not just paths.
        $tmpDir  = sys_get_temp_dir() . '/semitexa-fp-' . bin2hex(random_bytes(6));
        @mkdir($tmpDir, 0775, true);
        $tmpFile = $tmpDir . '/SyntheticResource.php';

        $synthClassName = 'Semitexa\\Core\\Tests\\Unit\\Resource\\Synth_' . bin2hex(random_bytes(4));

        $sourceA = "<?php\nnamespace Semitexa\\Core\\Tests\\Unit\\Resource;\n#[\\Semitexa\\Core\\Resource\\Attribute\\ResourceObject(type: 'fp.synthetic.a')]\nfinal readonly class " . substr($synthClassName, strrpos($synthClassName, '\\') + 1) . " implements \\Semitexa\\Core\\Resource\\ResourceObjectInterface {\n  public function __construct(#[\\Semitexa\\Core\\Resource\\Attribute\\ResourceId] public string \$id = '') {}\n}\n";
        file_put_contents($tmpFile, $sourceA);
        require_once $tmpFile;

        $f = ResourceMetadataSourceFingerprint::forTesting($this->discovery([$synthClassName]));
        $fp1 = $f->compute();

        // Mutate the file content (the class is already loaded in PHP, but
        // the fingerprint reads file content from disk, not reflection cache).
        file_put_contents($tmpFile, $sourceA . "\n// drift\n");
        $fp2 = $f->compute();

        self::assertNotSame($fp1, $fp2, 'File content change must flip the fingerprint.');

        @unlink($tmpFile);
        @rmdir($tmpDir);
    }

    #[Test]
    public function unloadable_class_falls_back_safely(): void
    {
        // Pass a class name that can't be reflected. Fingerprint must still
        // compute (no exception bubbling) so the rest of the boot continues.
        /** @var class-string $bogus */
        $bogus = 'Semitexa\\NonExistent\\Phantom_' . bin2hex(random_bytes(4));
        $f = ResourceMetadataSourceFingerprint::forTesting($this->discovery([$bogus]));

        $fp = $f->compute();
        self::assertSame(64, strlen($fp));
    }

    #[Test]
    public function empty_class_set_is_a_well_defined_fingerprint(): void
    {
        $f = ResourceMetadataSourceFingerprint::forTesting($this->discovery([]));
        $a = $f->compute();
        $b = $f->compute();

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
    }
}

// Phase 6m.5: the inline `InMemoryDiscoveryFor3d5` stub that lived
// here was extracted to `Fixtures/InMemoryDiscoveryFor3d5.php` so
// PSR-4 autoload finds it from any consuming test (notably
// `StaleCacheProtectionTest`), instead of relying on the test that
// happened to also be loaded by the runner.
