<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Which classes of a classmap carry which attribute: one scan answers every lookup, and the
 * scan is read from var/cache/discovery while the sources are the ones it was made from
 * (tk-discovery-registries-as-php-arrays).
 *
 * The scan loads every class of the map, which is how it reads them and why it is the
 * expensive half of discovery: 4300 classes into a worker that goes on to use 2200. A process
 * that reads the stored scan loads none of them.
 *
 * The stored scan is the answer for a classmap AND the state of its files, so it is read only
 * when the fingerprint of both is the one recorded and the scan is younger than every source.
 * Any edit therefore costs one scan, by whichever process boots first, exactly as before.
 *
 * @internal
 */
final class DiscoveryAttributeIndex
{
    /**
     * @param array<class-string, string> $classMap
     * @param string|null $storedHash content hash of the classmap a walk stored, null when this one came from no walk
     * @param \Closure(): bool $shouldAbort asked between two classes: true when the worker is draining
     * @return array{0: array<string, list<class-string>>, 1: array<string, string>} carriers by attribute name, and the classes that could not be inspected with why
     *
     * @throws DiscoveryInterruptedException
     */
    public static function readOrScan(array $classMap, string $projectRoot, ?string $storedHash, string $askedFor, \Closure $shouldAbort): array
    {
        $store = null;
        $fingerprint = '';
        if ($storedHash !== null && DiscoveryCacheStore::hashOf($classMap) === $storedHash) {
            $store = DiscoveryCacheStore::forProjectRoot($projectRoot);
        }

        if ($store !== null) {
            [$fingerprint, $newestSource] = self::sourceFingerprint($classMap, $projectRoot, (string) $storedHash);
            $pointer = $store->readState('attributes');
            // builtAt must be LATER than every source: a file saved in the second the scan ran
            // may have changed after it was read without its mtime showing it.
            if (is_array($pointer)
                && ($pointer['fingerprint'] ?? null) === $fingerprint
                && is_int($pointer['builtAt'] ?? null)
                && $pointer['builtAt'] > $newestSource
                && is_string($pointer['hash'] ?? null)
            ) {
                $stored = $store->load('attributes', $pointer['hash']);
                $read = $stored !== null ? self::attributesFromStored($stored) : null;
                if ($read !== null) {
                    // The scan that wrote the file reported these; a boot that reads it must too.
                    foreach ($read[1] as $className => $why) {
                        BootDiagnostics::current()->skip('ClassDiscovery', "Skipping {$className}: {$why}");
                    }

                    return $read;
                }
            }
        }

        $startedAt = time();
        [$attributes, $unloadable] = self::scan($classMap, $askedFor, $shouldAbort);

        if ($store !== null) {
            $payload = ['attributes' => $attributes, 'unloadable' => $unloadable];
            $shared = $store->share('attributes', $payload);
            $store->writeState('attributes', [
                'fingerprint' => $fingerprint,
                'builtAt' => $startedAt,
                'hash' => DiscoveryCacheStore::hashOf($payload),
            ]);

            return [$shared['attributes'], $shared['unloadable']];
        }

        return [$attributes, $unloadable];
    }

    /**
     * What the stored attribute scan depends on: the classmap, each class file's mtime and
     * size, and what Composer installed (a parent class or an attribute outside the map).
     *
     * @param array<class-string, string> $classMap
     * @return array{0: string, 1: int} the fingerprint, and the newest mtime among the sources
     */
    private static function sourceFingerprint(array $classMap, string $projectRoot, string $classMapHash): array
    {
        $hash = hash_init('xxh128');
        hash_update($hash, $classMapHash);
        $newest = 0;

        $files = array_values($classMap);
        $files[] = $projectRoot . '/vendor/composer/installed.php';
        foreach ($files as $file) {
            $stat = @stat($file);
            if ($stat === false) {
                hash_update($hash, "\0gone");
                continue;
            }
            hash_update($hash, "\0" . $stat['mtime'] . ':' . $stat['size']);
            $newest = max($newest, $stat['mtime']);
        }

        return [hash_final($hash), $newest];
    }

    /**
     * @param array<array-key, mixed> $stored
     * @return array{0: array<string, list<class-string>>, 1: array<string, string>}|null null when the file is not the shape this class writes
     */
    private static function attributesFromStored(array $stored): ?array
    {
        $attributes = $stored['attributes'] ?? null;
        $unloadable = $stored['unloadable'] ?? null;
        if (!is_array($attributes) || !is_array($unloadable)) {
            return null;
        }

        foreach ($attributes as $name => $carriers) {
            if (!is_string($name) || !is_array($carriers) || !array_is_list($carriers)) {
                return null;
            }
            foreach ($carriers as $carrier) {
                if (!is_string($carrier)) {
                    return null;
                }
            }
        }
        foreach ($unloadable as $className => $why) {
            if (!is_string($className) || !is_string($why)) {
                return null;
            }
        }

        // Returned as stored, never rebuilt: a rebuilt copy would live on this worker's heap.
        /** @var array<string, list<class-string>> $attributes */
        /** @var array<string, string> $unloadable */
        return [$attributes, $unloadable];
    }

    /**
     * @param array<string, list<class-string>> $index
     * @param array<class-string, string> $classMap
     * @return list<class-string>
     */
    public static function carriersOf(array $index, array $classMap, string $attributeClass, bool $instanceof): array
    {
        $matches = [];
        foreach ($index as $name => $carriers) {
            // PHP matches an attribute's name case-insensitively.
            if (strcasecmp($name, $attributeClass) === 0 || ($instanceof && self::isSubclass($name, $attributeClass))) {
                $matches[] = $carriers;
            }
        }

        if ($matches === []) {
            return [];
        }
        if (count($matches) === 1) {
            return $matches[0];
        }

        $carrying = [];
        foreach ($matches as $carriers) {
            foreach ($carriers as $carrier) {
                $carrying[$carrier] = true;
            }
        }

        // Once each, in classmap order, as one scan of the map gave them.
        return array_keys(array_intersect_key($classMap, $carrying));
    }

    private static function isSubclass(string $attributeName, string $parentClass): bool
    {
        try {
            return is_subclass_of($attributeName, $parentClass, true);
        } catch (\Throwable) {
            // An attribute whose own class does not load is an instance of nothing.
            return false;
        }
    }

    /**
     * One pass over the classmap: loads every class and records the attributes it names.
     *
     * @param array<class-string, string> $classMap
     * @param \Closure(): bool $shouldAbort
     * @return array{0: array<string, list<class-string>>, 1: array<string, string>}
     */
    private static function scan(array $classMap, string $askedFor, \Closure $shouldAbort): array
    {
        $attributes = [];
        $unloadable = [];
        // Every drop lands here as well as in the boot diagnostics, which a
        // console command never reads (orm:sync finished green over a resource
        // whose file did not load).
        $drop = static function (string $className, string $reason) use (&$unloadable): void {
            $unloadable[$className] = $reason;
        };

        $scanned = 0;
        foreach ($classMap as $className => $filePath) {
            // Same contract as the PSR-4 scan: between units, never inside one. Each
            // iteration autoloads a class, which is file I/O and therefore uncancellable.
            if ($shouldAbort()) {
                throw new DiscoveryInterruptedException(sprintf(
                    'Attribute discovery for %s stood down: the worker is draining (%d class(es) scanned).',
                    $askedFor,
                    $scanned,
                ), $scanned);
            }
            ++$scanned;

            try {
                $exists = class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true);
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('ClassDiscovery', "Skipping {$className} (load error): " . $e->getMessage(), $e);
                $drop($className, 'load error: ' . $e->getMessage());
                continue;
            }

            if (!$exists) {
                $why = "{$filePath} does not exist";
                if (is_file($filePath)) {
                    $why = "{$filePath} does not declare it";
                    try {
                        (static function (string $f): void { require_once $f; })($filePath);
                    } catch (\Throwable $e) {
                        BootDiagnostics::current()->skip('ClassDiscovery', "require_once failed for {$className} ({$filePath}): " . $e->getMessage(), $e);
                        $why = 'load error: ' . $e->getMessage();
                    }
                }
                if (!class_exists($className, false) && !interface_exists($className, false) && !trait_exists($className, false)) {
                    // These two paths used to record nothing at all.
                    if (!str_starts_with($why, 'load error')) {
                        BootDiagnostics::current()->skip('ClassDiscovery', "Skipping {$className}: {$why}");
                    }
                    $drop($className, $why);
                    continue;
                }
            }

            try {
                $named = [];
                foreach ((new \ReflectionClass($className))->getAttributes() as $attribute) {
                    $named[$attribute->getName()] = true;
                }
                foreach ($named as $name => $_) {
                    $attributes[$name][] = $className;
                }
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('ClassDiscovery', "Reading the attributes of {$className} failed: " . $e->getMessage(), $e);
                $drop($className, 'reflection failed: ' . $e->getMessage());
            }
        }

        return [$attributes, $unloadable];
    }
}
