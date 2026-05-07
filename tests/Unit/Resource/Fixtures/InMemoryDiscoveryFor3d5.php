<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Discovery\ClassDiscovery;

/**
 * Stub `ClassDiscovery` for fingerprint / cache-warm tests. Returns
 * the configured class list for any attribute query, so a test can
 * pin the discovered set without standing up a full classmap.
 *
 * Phase 6m.5: extracted from `SourceFingerprintTest.php` into a
 * Fixtures file so PSR-4 autoload finds it from any test that needs
 * it. Previously declared inline; that worked under a full-suite
 * run but failed when `ai:verify` invoked phpunit on a single file
 * that consumed the stub from a sibling test (e.g.
 * `StaleCacheProtectionTest`). The class is intentionally kept in
 * the `Fixtures` sub-namespace so Phase 6f.5's fixture-aware
 * `ai:verify` heuristic classifies it correctly and never tries to
 * run it as a PHPUnit test.
 */
final class InMemoryDiscoveryFor3d5 extends ClassDiscovery
{
    /** @param list<string> $classes */
    public function __construct(private readonly array $classes)
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->classes;
    }
}
