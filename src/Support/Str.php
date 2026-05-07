<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

class Str
{
    /**
     * Normalize an identifier into StudlyCase / PascalCase.
     *
     * Inputs that carry word separators (`-`, `_`, whitespace) are split and
     * each chunk is lower-then-upper-cased: `user_profile` / `user-profile`
     * / `USER PROFILE` all become `UserProfile`. Inputs that already look
     * like camelCase or PascalCase (no separators) are preserved as-is,
     * only the first character is upper-cased — so `SyncCommand`,
     * `UserImportCommand`, `XMLExportCommand` survive verbatim instead of
     * collapsing to `Synccommand`, `Userimportcommand`, `Xmlexportcommand`.
     *
     * Acronyms (`XML`, `OAuth`, `OpenApi`) are intentionally preserved when
     * the caller already typed them — Semitexa has no acronym-folding
     * convention, so user intent wins.
     */
    public static function toStudly(string $slug): string
    {
        if ($slug === '') {
            return '';
        }

        $hasSeparator = preg_match('/[-_\s]/', $slug) === 1;
        if (!$hasSeparator) {
            // Single token — preserve internal casing, just ensure the
            // first character is uppercase. ucfirst is a no-op when the
            // first character is already uppercase or non-alphabetic.
            return ucfirst($slug);
        }

        $parts = preg_split('/[-_\s]+/', $slug) ?: [];
        $parts = array_map(
            static fn ($p) => ucfirst(strtolower($p)),
            array_values(array_filter($parts, static fn ($p) => $p !== '')),
        );

        return $parts === [] ? '' : implode('', $parts);
    }

    public static function snakeToCamel(string $key): string
    {
        $parts = explode('_', $key);
        $first = (string) array_shift($parts);
        foreach ($parts as $i => $part) {
            $parts[$i] = ucfirst($part);
        }
        return $first . implode('', $parts);
    }
}
