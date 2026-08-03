<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Exception\ConfigurationException;

/**
 * Resolves a class's effective attributes by walking its declared `base:` chain
 * and letting nearer declarations win.
 *
 * The chain is the attribute-level analogue of inheritance and is deliberately
 * independent of it: a payload may declare `base: SomeOtherPayload::class`
 * without extending it, and discovery honours that link. Walking it is one
 * algorithm — resolve the parent, overlay the child, memoize — parameterized by
 * an {@see AttributeSchema}.
 *
 * Before ep-slay-attribute-discovery this existed twice inside AttributeDiscovery,
 * once for payloads and once for resources. The two copies had drifted into
 * *looking* different — one tested `($override[$k] ?? null) !== null`, the other
 * `array_key_exists()` followed by a null check — which is why they survived
 * review as separate code. They were exhaustively compared over every shape an
 * override slot can take, including the falsy traps `0`, `''`, `false` and `[]`:
 * 42 combinations, zero disagreements. The rule below is that single behaviour.
 *
 * @phpstan-type AttrMap array<string, mixed>
 */
final class AttributeChainResolver
{
    public function __construct(private readonly AttributeSchema $schema)
    {
    }

    /**
     * Resolve one class against a map of raw per-class metadata.
     *
     * `$cache` is passed by reference and shared across a whole discovery pass:
     * a deep chain is walked once, and every class hanging off it is answered
     * from memory afterwards.
     *
     * @param  array<string, array{class: string, short: string, attr: AttrMap, ...}> $metaMap
     * @param  array<string, AttrMap>                                                 $cache
     * @return AttrMap
     *
     * @throws ConfigurationException when a class names a `base:` that discovery never saw
     */
    public function resolve(string $className, array $metaMap, array &$cache): array
    {
        if (isset($cache[$className])) {
            return $cache[$className];
        }

        if (!isset($metaMap[$className])) {
            throw new ConfigurationException(sprintf(
                '%s metadata missing for %s',
                $this->schema->subject(),
                $className,
            ));
        }

        $meta = $metaMap[$className];
        /** @var AttrMap $attr */
        $attr = $meta['attr'];
        $base = is_string($attr['base'] ?? null) ? $attr['base'] : null;

        if ($base !== null && $base !== '') {
            $merged = $this->overlay($this->resolve($base, $metaMap, $cache), $attr);
        } else {
            $merged = $this->schema->applyDefaults($attr, $meta['short'], $className);
        }

        return $cache[$className] = $merged;
    }

    /**
     * Overlay a child's declared values onto its resolved base.
     *
     * A key counts as "declared" only when it is present AND non-null, so an
     * unset attribute argument inherits rather than blanking the base. That is
     * what lets a child override just the path and keep the base's transport,
     * access type and produces.
     *
     * @param  AttrMap $base
     * @param  AttrMap $override
     * @return AttrMap
     */
    private function overlay(array $base, array $override): array
    {
        $result = $base;
        foreach ($this->schema->mergeableKeys() as $key) {
            if (($override[$key] ?? null) !== null) {
                $result[$key] = $override[$key];
            }
        }

        return $result;
    }
}
