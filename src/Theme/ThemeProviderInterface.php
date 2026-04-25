<?php

declare(strict_types=1);

namespace Semitexa\Core\Theme;

/**
 * Per-request active theme chain for SSR.
 *
 * Implementations are supplied by packages that resolve themes from
 * request context (tenant / domain / locale / feature flags / ...).
 * When no implementation is bound, SSR falls back to the boot-time
 * `THEME` environment variable — preserving single-theme projects'
 * existing behavior.
 *
 * Returned order: leaf first, root last. For a theme hierarchy
 *   sky-acme extends theme-sky
 * the chain is `['sky-acme', 'theme-sky']`. SSR walks this chain at
 * template/asset resolution time, returning the first file that
 * exists in any `src/theme/<id>/<module>/` override directory before
 * falling back to the module default.
 *
 * An empty array means "no active chain" — SSR uses env `THEME` (or
 * nothing) just as it does today.
 */
interface ThemeProviderInterface
{
    /**
     * @return list<string> leaf-first theme chain, empty = no override
     */
    public function activeChain(): array;
}
