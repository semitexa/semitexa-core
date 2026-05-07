<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Resource\Fixtures;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Resource\RelationResolverInterface;
use Semitexa\Core\Resource\RenderContext;
use Semitexa\Core\Resource\ResourceIdentity;

/**
 * Phase 6g test fixture: nested resolver. Receives `ProfileResource`
 * identities (NOT root customer identities) and returns a
 * `PreferencesResource` per profile.
 */
#[AsService]
final class RecordingPreferencesResolver implements RelationResolverInterface
{
    /** @var list<array{count: int, parents: list<ResourceIdentity>, ctx: RenderContext}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function resolveBatch(array $parents, RenderContext $ctx): array
    {
        self::$calls[] = ['count' => count($parents), 'parents' => $parents, 'ctx' => $ctx];

        $out = [];
        foreach ($parents as $parent) {
            $out[$parent->urn()] = new PreferencesResource(
                id:    'prefs-' . $parent->id,
                theme: 'dark-' . $parent->id,
            );
        }
        return $out;
    }
}
