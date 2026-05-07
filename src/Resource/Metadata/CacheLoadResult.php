<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Metadata;

/**
 * Phase 3d.5 / 6m: structured outcome of a
 * `ResourceMetadataCacheFile::loadWithResult()` attempt. Distinguishes:
 *
 *   - Hit     — payload valid, fingerprint matches, registry hydrated.
 *   - Miss    — file absent.
 *   - Corrupt — file exists but its shape / version is unreadable.
 *   - Stale   — file is structurally valid but the source fingerprint
 *               recorded in the payload disagrees with the live source.
 *
 * Stale and Corrupt both rebuild + rewrite. Miss rebuilds + writes. Only
 * Hit avoids the discovery extraction path.
 */
enum CacheLoadResult: string
{
    case Hit     = 'hit';
    case Miss    = 'miss';
    case Corrupt = 'corrupt';
    case Stale   = 'stale';

    public function isHit(): bool
    {
        return $this === self::Hit;
    }

    public function shouldRebuild(): bool
    {
        return $this !== self::Hit;
    }
}
