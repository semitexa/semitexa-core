<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

class CodeExporter
{
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
}
