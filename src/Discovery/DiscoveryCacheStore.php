<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

/**
 * Where discovery keeps what it found between processes: `var/cache/discovery`
 * (tk-discovery-registries-as-php-arrays).
 *
 * Two kinds of file, because they are read for two different reasons.
 *
 * A SHARED ARRAY is a PHP file returning a plain array, loaded with `include`. OPcache keeps
 * such an array in shared memory as an immutable one, so every worker reads the same copy and
 * none builds it on its own heap. Three things make that true, and each was measured to fail
 * without it:
 *  - scalars only: one enum case or object in the array and PHP rebuilds the whole array on the
 *    heap of every process that includes it, so {@see share()} refuses anything else;
 *  - the file is named after its content and never rewritten: OPcache with
 *    validate_timestamps=0 would go on serving a rewritten file's old array, and a file that is
 *    never rewritten cannot be stale;
 *  - its mtime is set far in the past: OPcache does not cache a file younger than
 *    opcache.file_update_protection, counted from the start of the PROCESS, so a file written
 *    by a running server would stay uncached, and on the heap, until the next restart.
 *
 * A STATE record is a small JSON file read into memory and dropped: the stat table a walk
 * compares the tree against, and the pointer saying which shared array answers for the current
 * sources. These change with every edit, so they are not PHP: a changed PHP file is one more
 * dead script in OPcache.
 *
 * Every write goes to a temp file renamed into place, so a reader sees the old file or the new
 * one. A store that cannot write is not an error: discovery then works as it did before, in
 * memory.
 *
 * @internal
 */
final class DiscoveryCacheStore
{
    public const RELATIVE_DIRECTORY = 'var/cache/discovery';

    /** Bump when the shape of a stored file changes: an old directory is then simply not read. */
    private const FORMAT = 1;

    /** 2000-01-01: older than any server start, and not zero, which OPcache reads as "no time". */
    private const SETTLED_MTIME = 946684800;

    private function __construct(private readonly string $directory) {}

    /**
     * Null where there is no project to cache for: a root without `var/` is a fixture or a
     * package checked out alone, and writing a cache into it would be a file nobody asked for.
     */
    public static function forProjectRoot(string $projectRoot): ?self
    {
        if ($projectRoot === '' || !is_dir($projectRoot . '/var')) {
            return null;
        }

        // What a stored answer depends on besides the sources: the path the classes are read
        // under (a host and a container see the same tree at two paths), and the runtime, which
        // decides what tokenises and what loads.
        $context = hash('xxh128', implode("\0", [
            (string) self::FORMAT,
            $projectRoot,
            \PHP_VERSION,
            implode(',', get_loaded_extensions()),
        ]));

        return new self($projectRoot . '/' . self::RELATIVE_DIRECTORY . '/' . substr($context, 0, 16));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function hashOf(array $data): string
    {
        return hash('xxh128', serialize($data));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function readState(string $name): ?array
    {
        $file = $this->directory . '/' . $name . '.json';
        if (!is_file($file)) {
            return null;
        }

        try {
            $json = file_get_contents($file);
            $state = is_string($json) ? json_decode($json, true, 16, \JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            return null;
        }

        return is_array($state) ? $state : null;
    }

    /**
     * @param array<array-key, mixed> $state
     */
    public function writeState(string $name, array $state): bool
    {
        try {
            $json = json_encode($state, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            return false;
        }

        return $this->writeAtomically($this->directory . '/' . $name . '.json', $json, null);
    }

    /**
     * Stores the array under its content hash and returns the copy read back from the file,
     * which under OPcache is the shared one. Returns $data itself when it could not be stored
     * or does not read back identical, so the caller always holds a correct array.
     *
     * @template T of array<array-key, mixed>
     * @param T $data
     * @return T
     *
     * @throws \InvalidArgumentException when $data holds anything but scalars, null and arrays
     */
    public function share(string $name, array $data): array
    {
        self::assertScalarsOnly($data, $name);

        $hash = self::hashOf($data);
        $file = $this->sharedFile($name, $hash);

        if (!is_file($file)) {
            $php = "<?php\n\n// Generated by " . self::class . ". Named after its content: never edited, never rewritten.\n\nreturn "
                . var_export($data, true) . ";\n";
            if (!$this->writeAtomically($file, $php, self::SETTLED_MTIME)) {
                return $data;
            }
            $this->pruneOthers($name, $file);
        }

        $shared = $this->includeArray($file);
        if ($shared !== $data) {
            // A file of that name that does not hold that content: damaged or planted.
            @unlink($file);

            return $data;
        }

        /** @var T $shared identical to $data, so of its type */
        return $shared;
    }

    /**
     * The shared array stored under $hash, or null when it is gone or is not what its name says.
     *
     * @return array<array-key, mixed>|null
     */
    public function load(string $name, string $hash): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $hash) !== 1) {
            return null;
        }

        $shared = $this->includeArray($this->sharedFile($name, $hash));
        if ($shared === null || self::hashOf($shared) !== $hash) {
            return null;
        }

        return $shared;
    }

    public function sharedFile(string $name, string $hash): string
    {
        return $this->directory . '/' . $name . '-' . $hash . '.php';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function assertScalarsOnly(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::assertScalarsOnly($value, $path . '.' . $key);
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'A discovery cache holds scalars only: %s.%s is %s. An object or an enum case in the array makes every process rebuild it on its own heap; export its name or value and build the object where it is used.',
                    $path,
                    (string) $key,
                    get_debug_type($value),
                ));
            }
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function includeArray(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        try {
            $loaded = (static fn (string $f): mixed => include $f)($file);
        } catch (\Throwable) {
            return null;
        }

        return is_array($loaded) ? $loaded : null;
    }

    private function writeAtomically(string $file, string $content, ?int $mtime): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return false;
        }

        try {
            $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        } catch (\Throwable) {
            return false;
        }

        if (@file_put_contents($temp, $content) !== strlen($content)) {
            @unlink($temp);

            return false;
        }
        @chmod($temp, 0644);
        // Before the rename, which keeps it: the file is never visible with a young mtime.
        if (($mtime !== null && !@touch($temp, $mtime)) || !@rename($temp, $file)) {
            @unlink($temp);

            return false;
        }

        return true;
    }

    /**
     * Drops the shared arrays this one replaces. A process still about to include one finds it
     * gone, which reads as a miss, and builds its own.
     */
    private function pruneOthers(string $name, string $keep): void
    {
        $others = glob($this->directory . '/' . $name . '-*.php');
        foreach ($others === false ? [] : $others as $other) {
            if ($other === $keep) {
                continue;
            }
            @unlink($other);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($other, true);
            }
        }
    }
}
