<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

use Semitexa\Core\Lifecycle\CarriedContext;
use Semitexa\Core\Support\CoroutineLocal;

/**
 * The current request's flags, for code that lives as long as the worker and
 * so cannot be injected with them — a template function. Set by
 * CurrentFeatureFlagsListener in the route pipeline and by KissVisitor for a
 * render on the stream (which runs no pipeline); carried into the child
 * coroutines a render spawns. Outside a request every flag is off.
 */
final class CurrentFeatureFlags
{
    private const KEY = '__semitexa_feature_flags';

    public static function set(FeatureFlagsInterface $flags): void
    {
        CarriedContext::carry(self::KEY);
        CoroutineLocal::set(self::KEY, $flags);
    }

    public static function isEnabled(string $flag): bool
    {
        $flags = CoroutineLocal::get(self::KEY);

        return $flags instanceof FeatureFlagsInterface && $flags->isEnabled($flag);
    }
}
