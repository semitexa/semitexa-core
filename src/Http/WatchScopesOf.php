<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

use Semitexa\Core\Attribute\WatchScopes;
use Semitexa\Core\Contract\DeclaresWatchScopesInterface;

/**
 * A feed payload's watch scopes — the live subscription's scope keys and the
 * contract's `collection.live.scopes`, one answer for both: an explicit
 * #[WatchScopes] wins; otherwise a route attribute that DeclaresWatchScopesInterface.
 * Memoized per worker (both inputs are static per class).
 */
final class WatchScopesOf
{
    /** @var array<class-string, list<string>> */
    private static array $cache = [];

    /**
     * @param class-string $payloadClass
     * @return list<string>
     */
    public static function payload(string $payloadClass): array
    {
        if (isset(self::$cache[$payloadClass])) {
            return self::$cache[$payloadClass];
        }
        if (!class_exists($payloadClass)) {
            return [];
        }
        $ref = new \ReflectionClass($payloadClass);
        $explicit = $ref->getAttributes(WatchScopes::class);
        if ($explicit !== []) {
            /** @var WatchScopes $declared */
            $declared = $explicit[0]->newInstance();

            return self::$cache[$payloadClass] = $declared->scopes;
        }
        foreach ($ref->getAttributes(DeclaresWatchScopesInterface::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            /** @var DeclaresWatchScopesInterface $declares */
            $declares = $attribute->newInstance();

            return self::$cache[$payloadClass] = array_values($declares->watchScopes($payloadClass));
        }

        return self::$cache[$payloadClass] = [];
    }
}
