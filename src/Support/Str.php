<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

class Str
{
    public static function toStudly(string $slug): string
    {
        $parts = preg_split('/[-_]/', $slug);
        $parts = array_map(static fn($p) => ucfirst(strtolower($p)), array_filter($parts));

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
