<?php

declare(strict_types=1);

namespace Semitexa\Core\Log;

final class FallbackErrorLogger
{
    private const CONTROL_CHARACTER_ESCAPE_MAP = [
        "\0" => '\\0',
        "\a" => '\\a',
        "\b" => '\\b',
        "\t" => '\\t',
        "\n" => '\\n',
        "\v" => '\\v',
        "\f" => '\\f',
        "\r" => '\\r',
        "\e" => '\\e',
        "\177" => '\\x7f',
    ];

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

            $parts[] = sprintf(
                '%s=%s',
                self::escapeControlCharacters((string) $key),
                self::escapeControlCharacters($formatted),
            );
        }

        $suffix = $parts === [] ? '' : ' ' . implode(' ', $parts);

        error_log(sprintf('[Semitexa] %s%s', self::escapeControlCharacters($message), $suffix));
    }

    private static function escapeControlCharacters(string $value): string
    {
        $escaped = strtr($value, self::CONTROL_CHARACTER_ESCAPE_MAP);

        return (string) preg_replace_callback(
            '/[\x01-\x06\x0E-\x1F]/',
            static fn (array $matches): string => sprintf('\\x%02X', ord($matches[0])),
            $escaped,
        );
    }
}
