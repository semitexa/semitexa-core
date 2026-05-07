<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Resource\Exception\MalformedResourceObjectException;

#[AsService]
final class ResourceMetadataRegistry
{
    #[InjectAsReadonly]
    protected ResourceMetadataExtractor $extractor;

    /** @var array<class-string, ResourceObjectMetadata> */
    private array $byClass = [];

    /** @var array<string, class-string> */
    private array $byType = [];

    private bool $built = false;

    /** Bypass property injection for unit tests. */
    public static function forTesting(ResourceMetadataExtractor $extractor): self
    {
        $r = new self();
        $r->extractor = $extractor;
        return $r;
    }

    public function register(ResourceObjectMetadata $metadata): void
    {
        if (isset($this->byType[$metadata->type]) && $this->byType[$metadata->type] !== $metadata->class) {
            throw MalformedResourceObjectException::duplicateType(
                $metadata->type,
                $this->byType[$metadata->type],
                $metadata->class,
            );
        }

        $this->byClass[$metadata->class] = $metadata;
        $this->byType[$metadata->type]   = $metadata->class;
    }

    /** @param class-string $class */
    public function has(string $class): bool
    {
        return isset($this->byClass[$class]);
    }

    /** @param class-string $class */
    public function get(string $class): ?ResourceObjectMetadata
    {
        return $this->byClass[$class] ?? null;
    }

    /** @param class-string $class */
    public function require(string $class): ResourceObjectMetadata
    {
        $metadata = $this->byClass[$class] ?? null;
        if ($metadata === null) {
            throw new \RuntimeException(sprintf(
                'No ResourceObjectMetadata registered for class %s.',
                $class,
            ));
        }

        return $metadata;
    }

    public function findByType(string $type): ?ResourceObjectMetadata
    {
        $class = $this->byType[$type] ?? null;
        if ($class === null) {
            return null;
        }

        return $this->byClass[$class];
    }

    /** @return array<class-string, ResourceObjectMetadata> */
    public function all(): array
    {
        return $this->byClass;
    }

    public function buildFromDiscovery(ClassDiscovery $discovery): void
    {
        if ($this->built) {
            return;
        }

        $classes = $discovery->findClassesWithAttribute(ResourceObject::class);
        foreach ($classes as $class) {
            /** @var class-string $class */
            $metadata = $this->extractor->extract($class);
            $this->register($metadata);
        }

        $this->built = true;
    }

    /**
     * Phase 3d / 3d.5: idempotent worker-boot warmup with stale-cache
     * protection.
     *
     * Production (`$production === true`):
     *   1. Compute the source fingerprint from the live class set
     *      (`ResourceMetadataSourceFingerprint`).
     *   2. Try `loadWithResult($this, $fingerprint)`:
     *      - **Hit**     → registry hydrated, `built = true`. Done.
     *      - **Miss / Corrupt / Stale** → reset, rebuild from discovery,
     *        rewrite the cache with the new fingerprint.
     *
     * Non-production (dev/test):
     *   - Always rebuild from discovery; cache is bypassed and not written.
     *
     * Calling `ensureWarmed()` twice is a no-op once the registry is built.
     *
     * The `$fingerprint` service is optional for the dev/test path so callers
     * that don't need stale-cache protection can omit the dependency.
     */
    public function ensureWarmed(
        ClassDiscovery $discovery,
        ResourceMetadataCacheFile $cache,
        bool $production,
        ?ResourceMetadataSourceFingerprint $fingerprint = null,
    ): void {
        if ($this->built) {
            return;
        }

        if ($production) {
            // Compute fingerprint *before* loading so we can detect drift.
            // If no service was injected (legacy callers), pass null and the
            // cache load will skip the fingerprint check — Phase 3d behavior.
            $expectedFingerprint = $fingerprint?->compute();

            $result = $cache->loadWithResult($this, $expectedFingerprint);
            if ($result->isHit()) {
                $this->built = true;
                return;
            }
            // Fall through: Miss / Corrupt / Stale — rebuild + rewrite.
            $this->reset();
            $this->buildFromDiscovery($discovery);

            try {
                $cache->dump($this, $expectedFingerprint);
            } catch (\Throwable) {
                // A corrupted/RO cache must not crash boot. Next deploy +
                // `cache:clear` will recover.
            }
            return;
        }

        // Non-production: discovery-first, cache bypassed entirely.
        $this->reset();
        $this->buildFromDiscovery($discovery);
    }

    public function isWarmed(): bool
    {
        return $this->built;
    }

    public function reset(): void
    {
        $this->byClass = [];
        $this->byType  = [];
        $this->built   = false;
    }
}
