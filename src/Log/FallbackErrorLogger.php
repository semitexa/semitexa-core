<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

final class FallbackErrorLogger
{
    /**
     * @param array<array-key, mixed> $context
     */
    public static function log(string $message, array $context = []): void
    {
        $parts = [];

        foreach ($context as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_bool($value)) {
                $formatted = $value ? 'true' : 'false';
            } elseif (is_scalar($value)) {
                $formatted = (string) $value;
            } else {
                $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $formatted = is_string($encoded) ? $encoded : '[unserializable]';
            }

            $parts[] = sprintf('%s=%s', $key, $formatted);
        }

        $suffix = $parts === [] ? '' : ' ' . implode(' ', $parts);

        error_log(sprintf('[Semitexa] %s%s', $message, $suffix));
    }
}
