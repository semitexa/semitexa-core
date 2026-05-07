<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Core\Resource\Metadata\ResourceMetadataCacheFile;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceMetadataSourceFingerprint;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;

/**
 * Phase 3d: warm `ResourceMetadataRegistry` exactly once per worker on boot.
 *
 * Production (`APP_ENV === 'prod'`): cache-first.
 *   - If `var/cache/resource-metadata.php` exists and parses, hydrate the
 *     registry from it.
 *   - On miss / corrupt / wrong version, rebuild from `ClassDiscovery` and
 *     rewrite the cache (best-effort; a write failure does not crash boot).
 *
 * Any other env (dev/test): rebuild from discovery — cache is bypassed so
 * developer changes never hide behind a stale file. The cache is *not*
 * rewritten in dev to avoid surprising commits.
 *
 * Runs at `WorkerStartFinalize` so all module registries / class discovery
 * are already initialised by the time we extract metadata.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartFinalize->value,
    priority: 0,
    requiresContainer: true,
)]
final class WarmResourceMetadataListener implements ServerLifecycleListenerInterface
{
    public function __construct(
        private readonly ResourceMetadataRegistry $registry,
        private readonly ResourceMetadataCacheFile $cache,
        private readonly ClassDiscovery $classDiscovery,
        private readonly Environment $environment,
        private readonly ResourceMetadataSourceFingerprint $fingerprint,
    ) {
    }

    public function handle(ServerLifecycleContext $context): void
    {
        $this->registry->ensureWarmed(
            discovery:   $this->classDiscovery,
            cache:       $this->cache,
            production:  $this->environment->appEnv === 'prod',
            fingerprint: $this->fingerprint,
        );
    }
}
