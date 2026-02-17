<?php

declare(strict_types=1);

namespace Semitexa\Core\Util;

use Semitexa\Core\ModuleRegistry;

class CodeGenHelper
{
    public static function slugToStudly(string $slug): string
    {
        $parts = preg_split('/[-_]/', $slug);
        $parts = array_map(static fn($p) => ucfirst(strtolower($p)), array_filter($parts));

        return $parts === [] ? 'Module' : implode('', $parts);
    }

    public static function exportValue(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $items = [];
            foreach ($value as $k => $v) {
                $items[] = is_int($k) ? self::exportValue($v) : self::exportValue($k) . ' => ' . self::exportValue($v);
            }
            return '[' . implode(', ', $items) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }
        if ($value === null) {
            return 'null';
        }
        return (string) $value;
    }

    /**
     * @return array{name: string, studly: string}
     */
    public static function detectModule(string $file): array
    {
        $default = ['name' => 'project', 'studly' => 'Project'];
        if ($file === '') {
            return $default;
        }
        foreach (ModuleRegistry::getModules() as $module) {
            $path = $module['path'] ?? null;
            if ($path && str_starts_with($file, rtrim($path, '/') . '/')) {
                return [
                    'name' => $module['name'] ?? 'module',
                    'studly' => self::slugToStudly($module['name'] ?? 'module'),
                ];
            }
        }
        return $default;
    }

    public static function registerImport(string $fqn, array &$imports, array &$used, ?string $preferred = null): string
    {
        $fqn = ltrim($fqn, '\\');
        $pos = strrpos($fqn, '\\');
        $short = $pos === false ? $fqn : substr($fqn, $pos + 1);
        $alias = $preferred ?? $short;
        $c = 2;
        while (isset($used[$alias]) && $used[$alias] !== $fqn) {
            $alias = $short . $c++;
        }
        $used[$alias] = $fqn;
        $imports[] = ['fqn' => $fqn, 'short' => $short, 'alias' => $alias];
        return $alias;
    }
}
