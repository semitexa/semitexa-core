<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use Semitexa\Core\Support\ProjectRoot;

class ClassDiscovery
{
    /** @var array<class-string, string> */
    private array $classMap = [];
    private bool $initialized = false;
    /** @var array<string, list<class-string>> keyed by attribute cache key ('@instanceof:'-prefixed for instanceof queries) */
    private array $attributeCache = [];

    /**
     * Coroutine gates that serialise one-time, blocking-IO work per key.
     *
     * Both {@see initialize()} (recursive PSR-4 filesystem scan) and the
     * per-attribute discovery loop in {@see findClassesWithAttributeInternal()}
     * (autoload + reflection over the whole classmap) are coroutine SUSPENSION
     * points under SWOOLE_HOOK_ALL. Without a gate, the first concurrent burst
     * after a worker boot lets every coroutine enter the same scan at once — a
     * thundering herd of blocking file IO that stalls the worker reactor
     * (observed as intermittent hangs / 500s under load). Each gate elects a
     * single producer per key; every other coroutine suspends on the channel
     * and resumes once the cache is fully populated.
     *
     * STATIC, and keyed by project root, for the same reason the caches below are shared: an
     * instance-local gate only elects one producer per OBJECT. Once discovery results are
     * process-wide, two coroutines holding two fresh instances would each pass the shared-cache
     * check, each find its own gate empty, and each run the full scan — the very herd this
     * mechanism exists to prevent, just spread across objects instead of coroutines.
     *
     * The predicates handed to {@see runOncePerKey()} therefore have to consult the SHARED
     * cache, not instance state: a waiter that resumes on another instance would otherwise see
     * its own empty cache, decide nothing had been produced, and scan anyway.
     *
     * @var array<string, \Swoole\Coroutine\Channel>
     */
    private static array $coroutineGates = [];

    /** @var array<string, int> Coroutine id owning each in-flight gate (reentrancy guard). */
    private static array $coroutineGateOwners = [];

    /**
     * What discovery found, shared by every instance in this PROCESS, keyed by project root.
     *
     * The per-instance caches below are correct but far too small a unit. A worker holds ONE
     * classmap's worth of truth for its whole life — the filesystem does not change under it —
     * yet `new ClassDiscovery()` is the normal way to reach discovery from a service that has
     * no container (41 call sites do it through `new OrmManager()` alone). Every one of those
     * instances used to redo the entire PSR-4 walk: open every source directory, read every
     * PHP file, autoload every class in the map.
     *
     * That is fine once at boot and ruinous on a timer. Measured before this existed: a 5s
     * task tick reached settings through a fresh OrmManager, so worker 0 rebuilt the mapper
     * registry — and therefore rescanned every file — every five seconds, holding 60-75% of a
     * core and reading 12 MB/s, for as long as the server ran. One project's worker had read
     * 15 GB doing nothing. At PHP's default 128M limit the same worker instead reached the
     * memory cap mid-scan and was respawned into the next scan, forever.
     *
     * Keyed by project root, which is what makes this safe without an invalidation hook: a
     * test that chdirs into a fixture root simply reads a different bucket. Deliberately NOT
     * cleared from {@see ProjectRoot::reset()} — that call does not mean "the project moved".
     * {@see \Semitexa\Orm\OrmManager::createPool()} makes it under Swoole just to re-read
     * DB_* out of the env files, once per manager instance, so hanging invalidation off it
     * would wipe this cache on the very path it exists to protect. A caller that really did
     * change the sources under a running process calls {@see resetSharedCache()} itself.
     *
     * @var array<string, array<class-string, string>>
     */
    private static array $sharedClassMaps = [];

    /**
     * Attribute lookups shared across instances, keyed by project root then by attribute cache
     * key. Worth sharing separately from the classmap: {@see computeClassesWithAttribute()}
     * autoloads and reflects over EVERY class in the map, which is the expensive half.
     *
     * @var array<string, array<string, list<class-string>>>
     */
    private static array $sharedAttributeCaches = [];

    private array $allowedNamespacePrefixes = [
        'Semitexa\\' => true,
        'App\\' => true,
    ];

    /**
     * FQCN substrings that mark a class as dev-only and therefore excluded from the
     * runtime classmap. These classes typically extend interfaces that ship in
     * require-dev dependencies (phpstan/phpstan, phpunit/phpunit), which are absent
     * in consumer projects — scanning them triggers autoload errors and floods the
     * boot log with [skip] warnings. Filtering at classmap-population time keeps
     * them out of every downstream iteration.
     */
    private const RUNTIME_EXCLUDE_SUBSTRINGS = [
        '\\PHPStan\\',
        '\\Tests\\',
        '\\Testing\\PhpUnit',
    ];

    /**
     * Path-segment substrings that mark a SOURCE FILE as test-only and therefore
     * excluded from the runtime classmap, regardless of the class's namespace.
     *
     * Filtering by file path (rather than namespace alone) is required because
     * test fixtures may declare production-shaped namespaces — e.g. a fixture
     * under `packages/<pkg>/tests/Fixtures/...` declaring
     * `Semitexa\Modules\Website\...` would otherwise be promoted to a real
     * production route. Any class whose composer-classmap entry lives under
     * `/tests/` is dev-only.
     */
    private const RUNTIME_EXCLUDE_PATH_SEGMENTS = [
        '/tests/',
    ];

    public function initialize(): void
    {
        $projectRoot = ProjectRoot::get();

        $this->runOncePerKey(
            '@init',
            fn (): bool => $this->initialized || isset(self::$sharedClassMaps[$projectRoot]),
            function (): void {
                $this->runInitialization();
                $this->initialized = true;
            },
        );

        // A waiter woken by another INSTANCE's producer leaves the gate with the shared cache
        // populated but its own state untouched. Adopt it here rather than scanning again.
        if (!$this->initialized && isset(self::$sharedClassMaps[$projectRoot])) {
            $this->classMap = self::$sharedClassMaps[$projectRoot];
            $this->initialized = true;
        }
    }

    private function runInitialization(): void
    {
        $projectRoot = ProjectRoot::get();
        if (isset(self::$sharedClassMaps[$projectRoot])) {
            // Another instance in this process already walked this root. Skipping
            // refreshComposerAutoloader() with it is deliberate: that call mutates the
            // process-wide Composer ClassLoader, so the instance that populated the cache
            // already applied it and repeating it would add nothing.
            $this->classMap = self::$sharedClassMaps[$projectRoot];

            return;
        }

        $composerDir = $projectRoot . '/vendor/composer';
        $composerClassMap = $this->loadComposerClassMap($composerDir . '/autoload_classmap.php');
        $composerPsr4Map = $this->loadComposerPsr4Map($composerDir . '/autoload_psr4.php');

        $composerClassMap = array_filter(
            $composerClassMap,
            static fn (string $filePath): bool => is_file($filePath),
        );

        $this->refreshComposerAutoloader($composerDir, $composerClassMap);

        foreach ($composerClassMap as $className => $filePath) {
            if ($this->isNamespaceAllowed($className)
                && !$this->isRuntimeExcluded($className)
                && !self::isRuntimeExcludedByPath($filePath)
            ) {
                $this->classMap[$className] = $filePath;
            }
        }

        $this->mergePsr4ClassCandidates($composerPsr4Map);

        self::$sharedClassMaps[$projectRoot] = $this->classMap;
    }

    /**
     * Drop everything discovery has learned in this process.
     *
     * Only needed when sources change under a LIVE process and the project root stays the
     * same — a test that rewrites fixture files in place, essentially. Moving the root needs
     * nothing: the caches are keyed by root, so the new root starts empty on its own.
     */
    public static function resetSharedCache(): void
    {
        self::$sharedClassMaps = [];
        self::$sharedAttributeCaches = [];
        // The gates go with them. A closed gate left behind by a completed production would
        // outlive the cache it guarded: the next caller finds the predicate false, finds a
        // gate, pops a closed channel (which returns at once), loops, and spins forever.
        self::$coroutineGates = [];
        self::$coroutineGateOwners = [];
    }

    /**
     * @return array<class-string, string>
     */
    private function loadComposerClassMap(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            return [];
        }

        $classMap = [];
        foreach ($loaded as $className => $filePath) {
            if (is_string($className) && is_string($filePath)) {
                /** @var class-string $className */
                $classMap[$className] = $filePath;
            }
        }

        return $classMap;
    }

    /**
     * @return array<string, list<string>|string>
     */
    private function loadComposerPsr4Map(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            return [];
        }

        $psr4Map = [];
        foreach ($loaded as $namespace => $dirs) {
            if (!is_string($namespace)) {
                continue;
            }

            if (is_string($dirs)) {
                $psr4Map[$namespace] = $dirs;
                continue;
            }

            if (!is_array($dirs)) {
                continue;
            }

            $normalizedDirs = array_values(array_filter($dirs, static fn (mixed $dir): bool => is_string($dir)));
            $psr4Map[$namespace] = $normalizedDirs;
        }

        return $psr4Map;
    }

    /**
     * @return list<string>
     */
    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $this->findClassesWithAttributeInternal($attributeClass, false);
    }

    /**
     * Same as findClassesWithAttribute but matches subclasses of $attributeClass too
     * (uses ReflectionAttribute::IS_INSTANCEOF).
     *
     * Used by the routable-payload discovery path: AbstractPayloadRoute is the
     * shared base class for AsPublicPayload, AsProtectedPayload, and
     * AsServicePayload (all three live in semitexa-authorization). Querying by
     * the abstract base lets the framework discover payloads without coupling
     * semitexa-core to the concrete attribute classes.
     *
     * @return list<string>
     */
    public function findClassesWithAttributeInstanceof(string $attributeParentClass): array
    {
        return $this->findClassesWithAttributeInternal($attributeParentClass, true);
    }

    /**
     * @return list<string>
     */
    private function findClassesWithAttributeInternal(string $attributeClass, bool $instanceof): array
    {
        $cacheKey = $instanceof ? '@instanceof:' . $attributeClass : $attributeClass;

        if (isset($this->attributeCache[$cacheKey])) {
            return $this->attributeCache[$cacheKey];
        }

        $this->initialize();

        $projectRoot = ProjectRoot::get();
        if (isset(self::$sharedAttributeCaches[$projectRoot][$cacheKey])) {
            return $this->attributeCache[$cacheKey] = self::$sharedAttributeCaches[$projectRoot][$cacheKey];
        }

        $this->runOncePerKey(
            'attr:' . $cacheKey,
            fn (): bool => isset($this->attributeCache[$cacheKey])
                || isset(self::$sharedAttributeCaches[$projectRoot][$cacheKey]),
            function () use ($attributeClass, $instanceof, $cacheKey, $projectRoot): void {
                $computed = $this->computeClassesWithAttribute($attributeClass, $instanceof);
                $this->attributeCache[$cacheKey] = $computed;
                self::$sharedAttributeCaches[$projectRoot][$cacheKey] = $computed;
            },
        );

        // Woken by another instance's producer — take its answer instead of recomputing.
        if (!isset($this->attributeCache[$cacheKey]) && isset(self::$sharedAttributeCaches[$projectRoot][$cacheKey])) {
            $this->attributeCache[$cacheKey] = self::$sharedAttributeCaches[$projectRoot][$cacheKey];
        }

        return $this->attributeCache[$cacheKey] ?? [];
    }

    /**
     * @return list<class-string>
     */
    private function computeClassesWithAttribute(string $attributeClass, bool $instanceof): array
    {
        $reflectionFlags = $instanceof ? \ReflectionAttribute::IS_INSTANCEOF : 0;
        $classes = [];

        foreach ($this->classMap as $className => $filePath) {
            try {
                $exists = class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true);
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('ClassDiscovery', "Skipping {$className} (load error): " . $e->getMessage(), $e);
                continue;
            }

            if (!$exists) {
                $filePath = $this->classMap[$className];
                if (is_file($filePath)) {
                    try {
                        (static function (string $f): void { require_once $f; })($filePath);
                    } catch (\Throwable $e) {
                        BootDiagnostics::current()->skip('ClassDiscovery', "require_once failed for {$className} ({$filePath}): " . $e->getMessage(), $e);
                    }
                }
                if (!class_exists($className, false) && !interface_exists($className, false) && !trait_exists($className, false)) {
                    continue;
                }
            }

            try {
                $reflection = new \ReflectionClass($className);
                $attrs = $reflection->getAttributes($attributeClass, $reflectionFlags);

                if ($attrs) {
                    $classes[] = $className;
                }
            } catch (\Throwable $e) {
                BootDiagnostics::current()->skip('ClassDiscovery', "findClassesWithAttribute({$attributeClass}, instanceof={$instanceof}) failed for {$className}: " . $e->getMessage(), $e);
            }
        }

        return $classes;
    }

    /**
     * Runs $produce at most once per $key across concurrent coroutines.
     *
     * The first coroutine to arrive for a key runs $produce; concurrent callers
     * suspend on a one-shot gate channel and resume once it completes, then read
     * the populated cache via their own accessor. Outside a Swoole coroutine
     * (CLI, tests, single-threaded worker boot) it degrades to a plain
     * compute-if-absent with no synchronisation.
     *
     * There is no coroutine suspension point between the top guard and the gate
     * creation below (no IO, no channel op), so exactly one coroutine can become
     * the producer for a key. On producer failure the gate is dropped and the
     * failing coroutine rethrows; waiters wake, re-check $isDone() and — since
     * the gate is gone — the next one becomes the producer and retries, so no
     * caller ever proceeds on incomplete state. On success the closed gate is
     * retained and every future call short-circuits on $isDone().
     *
     * @param callable(): bool $isDone
     * @param callable(): void $produce
     */
    private function runOncePerKey(string $key, callable $isDone, callable $produce): void
    {
        // Root-scoped, because the gates are process-wide: two project roots in one process
        // (a test that chdirs into a fixture) must not queue behind each other's production.
        $key = ProjectRoot::get() . "\0" . $key;

        while (true) {
            if ($isDone()) {
                return;
            }

            // Outside a coroutine there is no concurrency and no suspension point
            // between here and the guard above, so produce inline.
            if (!$this->inCoroutine()) {
                $produce();
                return;
            }

            /** @var int $currentCid Swoole\Coroutine::getCid() is int (its stub is untyped) */
            $currentCid = \Swoole\Coroutine::getCid();

            if (isset(self::$coroutineGates[$key])) {
                // Reentrant call on the producing coroutine must not wait on itself.
                if ((self::$coroutineGateOwners[$key] ?? -1) === $currentCid) {
                    return;
                }
                // Another coroutine owns production — block until it closes the
                // gate, then loop: a successful producer makes $isDone() short-
                // circuit; a failed one dropped the gate, so this coroutine
                // retries as the new producer rather than continuing on an
                // incomplete cache.
                self::$coroutineGates[$key]->pop();
                continue;
            }

            $gate = new \Swoole\Coroutine\Channel(1);
            self::$coroutineGates[$key] = $gate;
            self::$coroutineGateOwners[$key] = $currentCid;

            // We are the elected producer; no other coroutine can have produced
            // between the top guard and here (no suspension point above), so the
            // cache is still cold.
            try {
                $produce();
            } catch (\Throwable $e) {
                // Failed production must not wedge waiters behind a permanently
                // closed gate, nor let them proceed on incomplete state — drop
                // the gate and wake them so the next caller retries.
                unset(self::$coroutineGates[$key], self::$coroutineGateOwners[$key]);
                $gate->close();
                throw $e;
            }

            // Success: wake every waiter. The closed gate stays in the map so late
            // arrivals resolve via the cheap $isDone() short-circuit above.
            unset(self::$coroutineGateOwners[$key]);
            $gate->close();
            return;
        }
    }

    private function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() >= 0;
    }

    /**
     * @return array<class-string, string>
     */
    public function getClassMap(): array
    {
        $this->initialize();

        return $this->classMap;
    }

    /**
     * @param array<class-string, string> $freshClassMap
     */
    private function refreshComposerAutoloader(string $composerDir, array $freshClassMap): void
    {
        try {
            $psr4File = $composerDir . '/autoload_psr4.php';
            /** @var array<string, list<string>|string> $freshPsr4 */
            $freshPsr4 = is_file($psr4File) ? (require $psr4File) : [];

            foreach (spl_autoload_functions() as $loader) {
                if (!is_array($loader) || !($loader[0] instanceof \Composer\Autoload\ClassLoader)) {
                    continue;
                }
                /** @var \Composer\Autoload\ClassLoader $classLoader */
                $classLoader = $loader[0];
                $classLoader->addClassMap($freshClassMap);
                foreach ($freshPsr4 as $namespace => $dirs) {
                    $classLoader->addPsr4($namespace, $dirs);
                }
                break;
            }
        } catch (\Throwable) {
            // Autoloader refresh is best-effort; never block initialization.
        }
    }

    /**
     * @param array<string, list<string>|string> $psr4Map
     */
    private function mergePsr4ClassCandidates(array $psr4Map): void
    {
        uksort($psr4Map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $seenRealPaths = [];
        foreach ($psr4Map as $namespace => $dirs) {
            if (!$this->isNamespaceAllowed($namespace)) {
                continue;
            }

            foreach ((array) $dirs as $dir) {
                if (!$this->shouldMergePsr4Directory($dir)) {
                    continue;
                }

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $dir,
                        \FilesystemIterator::SKIP_DOTS,
                    ),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD,
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                        continue;
                    }

                    $realPath = $fileInfo->getRealPath();
                    if ($realPath !== false && isset($seenRealPaths[$realPath])) {
                        continue;
                    }

                    $className = self::extractDeclaredClassName($fileInfo->getPathname());
                    if ($className === null
                        || !$this->isNamespaceAllowed($className)
                        || $this->isRuntimeExcluded($className)
                        || self::isRuntimeExcludedByPath($fileInfo->getPathname())
                    ) {
                        continue;
                    }

                    if (!isset($this->classMap[$className])) {
                        /** @var class-string $className */
                        $this->classMap[$className] = $fileInfo->getPathname();
                        if ($realPath !== false) {
                            $seenRealPaths[$realPath] = true;
                        }
                    }
                }
            }
        }
    }

    private function shouldMergePsr4Directory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $projectRoot = ProjectRoot::get();
        $projectRootReal = realpath($projectRoot) ?: $projectRoot;
        $vendorRoot = $projectRoot . '/vendor/';
        $vendorRootReal = $projectRootReal . '/vendor/';
        $realPath = realpath($dir);
        if ($realPath === false) {
            return false;
        }

        if (($realPath === $projectRootReal . '/src' || str_starts_with($realPath, $projectRootReal . '/src/'))
            || ($realPath === $projectRootReal . '/tests' || str_starts_with($realPath, $projectRootReal . '/tests/'))
            || ($realPath === $projectRootReal . '/packages' || str_starts_with($realPath, $projectRootReal . '/packages/'))
        ) {
            return true;
        }

        if (($realPath === $projectRootReal . '/vendor/semitexa' || str_starts_with($realPath, $projectRootReal . '/vendor/semitexa/'))
            && str_starts_with($dir, $vendorRoot)
        ) {
            return true;
        }

        return str_starts_with($dir, $vendorRoot) && !str_starts_with($realPath, $vendorRootReal);
    }

    private static function extractDeclaredClassName(string $filePath): ?string
    {
        $source = @file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $collectNamespace = false;
        $collectClass = false;
        /** @var int|string|null $previousSignificantToken */
        $previousSignificantToken = null;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                if ($collectNamespace && ($token === ';' || $token === '{')) {
                    $collectNamespace = false;
                }
                if (trim($token) !== '') {
                    $previousSignificantToken = $token;
                }
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_NAMESPACE) {
                $namespace = '';
                $collectNamespace = true;
                continue;
            }

            if ($collectNamespace) {
                if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NS_SEPARATOR) {
                    $namespace .= $text;
                }
                continue;
            }

            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
                if ($previousSignificantToken === T_DOUBLE_COLON) {
                    continue;
                }
                if ($id === T_CLASS && $previousSignificantToken === T_NEW) {
                    continue;
                }
                $collectClass = true;
                $previousSignificantToken = $id;
                continue;
            }

            if ($collectClass && $text === '{') {
                $collectClass = false;
                continue;
            }

            if ($collectClass && $id === T_STRING) {
                return $namespace !== '' ? $namespace . '\\' . $text : $text;
            }

            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previousSignificantToken = $id;
            }
        }

        return null;
    }

    private function isNamespaceAllowed(string $className): bool
    {
        foreach (array_keys($this->allowedNamespacePrefixes) as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function isRuntimeExcluded(string $className): bool
    {
        if (str_starts_with($className, 'Semitexa\\Core\\Composer\\')) {
            return true;
        }

        foreach (self::RUNTIME_EXCLUDE_SUBSTRINGS as $needle) {
            if (str_contains($className, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filters by the class's source file path. Closes the gap where a test
     * fixture under `packages/<pkg>/tests/Fixtures/...` declares a non-test
     * namespace (PSR-4 violation that composer warns about but still includes
     * in the classmap). The namespace check would let it through — the path
     * check rejects it because no production class lives under `/tests/`.
     *
     * Path normalization is permissive on direction so Windows backslashes
     * and Unix forward slashes both match.
     */
    private static function isRuntimeExcludedByPath(string $filePath): bool
    {
        $normalized = str_replace('\\', '/', $filePath);
        foreach (self::RUNTIME_EXCLUDE_PATH_SEGMENTS as $segment) {
            if (str_contains($normalized, $segment)) {
                return true;
            }
        }
        return false;
    }
}
