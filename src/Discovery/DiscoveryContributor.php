<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * A package's hook into boot-time attribute discovery.
 *
 * Discovery knows how to find every class carrying a given attribute, filter it
 * by module activation, and reflect the attribute instance — but it has no
 * business knowing what any particular attribute *means*. A contributor supplies
 * exactly that missing half: name an attribute, and say what to do with each hit.
 *
 * This exists to invert a dependency that used to run the wrong way.
 * AttributeDiscovery carried twelve hardcoded `Semitexa\Ssr\…` strings and called
 * three SSR registries directly, each wrapped in a `class_exists()` guard. The
 * guards made it *look* optional, which is precisely why nobody noticed that
 * semitexa-core had grown a runtime dependency on semitexa-ssr that its
 * composer.json never declared — a `class_exists()` on a package you do not
 * require is an undeclared dependency wearing a disguise. Now ssr ships its own
 * contributors and core names no downstream package at all.
 *
 * Implementations are found by their {@see AsDiscoveryContributor} attribute, the
 * same self-hosting trick core already uses for pipeline listeners, server
 * lifecycle hooks and resource metadata.
 */
interface DiscoveryContributor
{
    /**
     * Fully-qualified name of the attribute class this contributor consumes.
     *
     * Returning an attribute whose class is not loadable is not an error — the
     * owning package is simply not installed, and discovery skips the
     * contributor. That is the one piece of `class_exists()` behaviour worth
     * keeping, and it now lives in one place instead of four.
     */
    public function attribute(): string;

    /**
     * Whether hits must come from an active module or the project's own src/.
     *
     * Most contributions are module-scoped: a slot declared by a module the
     * current tenant has not enabled must not register. Framework-level
     * contributions that apply regardless of tenant module configuration return
     * false and see every discovered class.
     */
    public function scopedToActiveModules(): bool;

    /**
     * Handle one discovered attribute instance.
     *
     * Called once per attribute occurrence, so a class carrying an attribute
     * repeatably is visited once per declaration. Throwing is safe and expected
     * for a genuinely invalid declaration: discovery records it against
     * `$diagnostics` and carries on, so one malformed class cannot abort a boot.
     *
     * @param string $className The class the attribute was found on.
     * @param object $attribute The instantiated attribute.
     */
    public function contribute(string $className, object $attribute, BootDiagnostics $diagnostics): void;
}
