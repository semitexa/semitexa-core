<?php

declare(strict_types=1);

namespace Semitexa\Core\Util;

use Semitexa\Core\ModuleRegistry;

class CodeGenHelper
{
    public static function slugToStudly(string $slug): string
    {
        return \Semitexa\Core\Support\Str::toStudly($slug) ?: 'Module';
    }

    public static function exportValue(mixed $value): string
    {
        return \Semitexa\Core\Support\CodeExporter::exportValue($value);
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
