<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * {@see AttributeSchema} for `#[AsResource]` response classes.
 *
 * Unlike payloads, nothing here is mandatory: a resource that declares no
 * `handle` still needs one, so the short name supplies it. That derivation is
 * how "AboutResponse" finds `pages/about.html.twig` without anyone writing the
 * mapping down.
 *
 * @phpstan-type AttrMap array<string, mixed>
 */
final class ResourceAttributeSchema implements AttributeSchema
{
    public function subject(): string
    {
        return 'Response';
    }

    /**
     * @return list<string>
     */
    public function mergeableKeys(): array
    {
        return ['handle', 'format', 'renderer', 'template', 'context', 'produces'];
    }

    /**
     * @param  AttrMap $attr
     * @return AttrMap
     */
    public function applyDefaults(array $attr, string $shortName, string $className): array
    {
        return [
            'handle' => $attr['handle'] ?? self::defaultLayoutHandleFromShortName($shortName),
            'format' => $attr['format'] ?? null,
            'renderer' => $attr['renderer'] ?? null,
            'template' => $attr['template'] ?? null,
            'context' => \array_key_exists('context', $attr) ? $attr['context'] : null,
            'produces' => $attr['produces'] ?? null,
        ];
    }

    /**
     * Default layout/template handle from a Response class short name.
     * "AboutResponse" -> "about", "HomeResponse" -> "home", so it matches
     * pages/{handle}.html.twig.
     */
    public static function defaultLayoutHandleFromShortName(string $shortName): string
    {
        if (str_ends_with($shortName, 'Response')) {
            $shortName = substr($shortName, 0, -8);
        }

        return strtolower(ltrim(preg_replace('/[A-Z]/', '-$0', $shortName), '-'));
    }
}
