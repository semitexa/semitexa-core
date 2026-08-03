<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * The per-axis policy behind {@see AttributeChainResolver}.
 *
 * Discovery resolves two families of attributes that behave identically in the
 * large — each declares an optional `base:` pointing at another class, and a
 * subclass's own values win over whatever it inherits — but differ in exactly
 * three details: which keys participate, what an unparented class defaults to,
 * and what the family is called when a lookup fails. Those three are this
 * interface; the walk itself is the resolver's, written once.
 *
 * @phpstan-type AttrMap array<string, mixed>
 */
interface AttributeSchema
{
    /**
     * Human name of the attribute family ("Request", "Response"), used to make
     * a failed metadata lookup say which discovery pass went wrong.
     */
    public function subject(): string;

    /**
     * Keys a child may override on its base. A key absent from this list is
     * inherited untouched no matter what the child declares.
     *
     * @return list<string>
     */
    public function mergeableKeys(): array;

    /**
     * Complete the attributes of a class that declares no `base:` — filling in
     * defaults and rejecting declarations that cannot stand alone.
     *
     * @param  AttrMap $attr
     * @return AttrMap
     */
    public function applyDefaults(array $attr, string $shortName, string $className): array;
}
