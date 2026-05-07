<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

use ReflectionClass;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Resource\Attribute\ResourceObject;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Phase 3d.5: deterministic fingerprint over the discovered Resource DTO
 * source set. The fingerprint changes when:
 *
 *   - a Resource DTO is added / removed from the discovered classmap, OR
 *   - a Resource DTO source file's content changes (any byte — class
 *     rename, attribute value change, property addition, comment edit).
 *
 * A worker that boots from a stale `var/cache/resource-metadata.php`
 * compares its recorded fingerprint to a freshly-computed one; on
 * mismatch the cache is treated as Stale (see `CacheLoadResult`) and the
 * registry rebuilds + rewrites.
 *
 * Discovery on every prod boot is acceptable here: it walks the existing
 * composer classmap (already loaded), skips full reflection-based metadata
 * extraction, and only reads file paths + content hashes. The expensive
 * step (`ResourceMetadataExtractor::extract()`) only runs on miss / stale.
 *
 * Pure: no DB, no ORM, no HTTP, no Request, no renderer, no IriBuilder.
 */
#[AsService]
final class ResourceMetadataSourceFingerprint
{
    /**
     * Algorithm version. Bump when changing the input shape so old caches
     * (with a different fingerprint algorithm) don't false-match.
     */
    public const ALGORITHM_VERSION = 1;

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    public static function forTesting(ClassDiscovery $classDiscovery): self
    {
        $f = new self();
        $f->classDiscovery = $classDiscovery;
        return $f;
    }

    /**
     * @return string sha256 hex digest, deterministic across hosts when
     *                files share the same relative path under ProjectRoot
     */
    public function compute(): string
    {
        $classes = $this->classDiscovery->findClassesWithAttribute(ResourceObject::class);
        sort($classes, SORT_STRING);

        $entries = [];
        $projectRoot = rtrim(ProjectRoot::get(), '/');

        foreach ($classes as $class) {
            $entries[] = $this->fingerprintEntry($class, $projectRoot);
        }

        $payload = [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'classes'           => $entries,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param class-string $class
     * @return array{class: string, path: string, hash: string|null}
     */
    private function fingerprintEntry(string $class, string $projectRoot): array
    {
        $relativePath = null;
        $contentHash  = null;

        try {
            $reflection = new ReflectionClass($class);
            $filePath   = $reflection->getFileName();
            if (is_string($filePath) && is_file($filePath)) {
                // Normalise path relative to project root so two hosts
                // (CI vs prod) produce the same fingerprint.
                if ($projectRoot !== '' && str_starts_with($filePath, $projectRoot . '/')) {
                    $relativePath = substr($filePath, strlen($projectRoot) + 1);
                } else {
                    // Best-effort: fall back to a basename when the file
                    // lives outside the project root (vendor, ext-loaded).
                    $relativePath = '<external>/' . basename($filePath);
                }
                $contentHash = hash_file('sha256', $filePath) ?: null;
            }
        } catch (\Throwable) {
            // A class that can't reflect (e.g., dynamically generated or
            // missing) collapses to a class-name-only entry. The cache
            // will mismatch the next time the class becomes loadable,
            // forcing a rebuild — never a silent stale-load.
        }

        return [
            'class' => $class,
            'path'  => $relativePath ?? '<unknown>',
            'hash'  => $contentHash,
        ];
    }
}
