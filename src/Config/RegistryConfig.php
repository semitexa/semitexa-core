<?php

declare(strict_types=1);

namespace Semitexa\Core\Config;

use Semitexa\Core\Util\ProjectRoot;

/**
 * Reads registry-related config from composer.json "extra.semitexa.registry".
 * Used by registry:sync:* commands. Does not run at application runtime.
 *
 * Example in composer.json:
 *   "extra": {
 *     "semitexa": {
 *       "registry": {
 *         "use_manifest": false
 *       }
 *     }
 *   }
 *
 * When "use_manifest" is false, sync commands do not read or write src/registry/manifest.json;
 * they use only discovery (AsPayload/AsPayloadPart, AsResource/AsResourcePart).
 * Default is true (backward compatible).
 */
final class RegistryConfig
{
    private static ?array $extra = null;

    /**
     * Whether to use manifest.json as input/output for registry sync.
     * When false, sync uses only discovered classes and does not write manifest.
     */
    public static function useManifest(): bool
    {
        $config = self::getRegistryConfig();
        return $config['use_manifest'] ?? true;
    }

    /**
     * Full extra.semitexa.registry array from composer.json.
     *
     * @return array<string, mixed>
     */
    public static function getRegistryConfig(): array
    {
        $extra = self::getSemitexaExtra();
        return is_array($extra['registry'] ?? null) ? $extra['registry'] : [];
    }

    /**
     * extra.semitexa from composer.json (cached for the request).
     *
     * @return array<string, mixed>
     */
    private static function getSemitexaExtra(): array
    {
        if (self::$extra !== null) {
            return self::$extra;
        }
        $root = ProjectRoot::get();
        $path = $root . '/composer.json';
        if (!is_file($path)) {
            self::$extra = [];
            return self::$extra;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            self::$extra = [];
            return self::$extra;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$extra = [];
            return self::$extra;
        }
        $extra = $data['extra'] ?? null;
        self::$extra = is_array($extra) && isset($extra['semitexa']) && is_array($extra['semitexa'])
            ? $extra['semitexa']
            : [];
        return self::$extra;
    }

    public static function reset(): void
    {
        self::$extra = null;
    }
}
