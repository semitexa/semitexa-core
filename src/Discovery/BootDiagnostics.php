<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

final class BootDiagnostics
{
    /** Do not emit any boot diagnostic output (strict-mode exceptions still throw). */
    public const VERBOSITY_SILENT = 0;

    /** Default. Emit a compact one-line summary plus per-component counts when warnings occur. */
    public const VERBOSITY_SUMMARY = 1;

    /** Legacy behavior — emit every warning on its own line. Controlled by -v / --verbose / BOOT_VERBOSE=1. */
    public const VERBOSITY_VERBOSE = 2;

    private static ?self $current = null;

    /** @var list<BootWarning> */
    private array $warnings = [];

    private int $verbosity = self::VERBOSITY_SUMMARY;

    public static function begin(): self
    {
        self::$current = new self();
        self::$current->verbosity = self::detectVerbosity();
        return self::$current;
    }

    public static function current(): self
    {
        return self::$current ?? self::begin();
    }

    public function skip(string $component, string $message, ?\Throwable $cause = null): void
    {
        $this->warnings[] = new BootWarning(BootSeverity::Skip, $component, $message, $cause);
    }

    public function invalidUsage(string $component, string $message, ?\Throwable $cause = null): void
    {
        $this->warnings[] = new BootWarning(BootSeverity::InvalidUsage, $component, $message, $cause);
    }

    public function fatal(string $component, string $message, ?\Throwable $cause = null): never
    {
        throw new BootException("[{$component}] {$message}", $cause);
    }

    public function setVerbosity(int $level): void
    {
        $this->verbosity = $level;
    }

    public function getVerbosity(): int
    {
        return $this->verbosity;
    }

    /**
     * Call after build completes. Emits boot diagnostics according to the current verbosity.
     *
     * Verbosity modes:
     *   - SILENT: no output (strict-mode exceptions still throw).
     *   - SUMMARY (default): one-line summary plus per-component counts when warnings > 0.
     *   - VERBOSE: every warning on its own line (legacy behavior).
     *
     * In strict mode, InvalidUsage warnings become fatal regardless of verbosity; the thrown
     * BootException always carries the full detail summary (independent of verbosity).
     */
    public function finalize(bool $strict = false): void
    {
        if ($this->warnings === []) {
            return;
        }

        $skips = array_filter($this->warnings, fn(BootWarning $w) => $w->severity === BootSeverity::Skip);
        $invalid = array_filter($this->warnings, fn(BootWarning $w) => $w->severity === BootSeverity::InvalidUsage);

        // Full-detail summary shared by strict-mode exception and VERBOSE output.
        $fullSummary = sprintf(
            "Boot completed: %d skip(s), %d invalid usage(s)\n",
            count($skips),
            count($invalid),
        );
        foreach ($this->warnings as $w) {
            $fullSummary .= sprintf("  [%s][%s] %s\n", $w->severity->value, $w->component, $w->message);
        }

        if ($strict && $invalid !== []) {
            throw new BootException($fullSummary);
        }

        if ($this->verbosity === self::VERBOSITY_SILENT) {
            return;
        }

        if ($this->verbosity === self::VERBOSITY_VERBOSE) {
            error_log($fullSummary);
            return;
        }

        // VERBOSITY_SUMMARY — compact, grouped overview.
        $byComponent = [];
        foreach ($this->warnings as $w) {
            $key = sprintf('[%s][%s]', $w->severity->value, $w->component);
            $byComponent[$key] = ($byComponent[$key] ?? 0) + 1;
        }
        $lines = [sprintf(
            "Boot completed: %d skip(s), %d invalid usage(s) — run with -v or BOOT_VERBOSE=1 for details",
            count($skips),
            count($invalid),
        )];
        foreach ($byComponent as $label => $count) {
            $lines[] = "  {$label}: {$count}";
        }
        error_log(implode("\n", $lines) . "\n");
    }

    /** @return list<BootWarning> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Pick a sensible default verbosity from the environment.
     *
     * Resolution order:
     *   1. BOOT_VERBOSE env var (Swoole/FPM/CLI-safe, process-level, always honored)
     *   2. CLI argv flags (-v / --verbose / -q / --quiet) — checked only when argv
     *      is actually available and looks like CLI invocation args
     *   3. SUMMARY (safe default)
     *
     * Swoole note: BootDiagnostics::begin() runs during ContainerBootstrapper::build(),
     * which happens at master-process boot BEFORE workers fork and BEFORE any HTTP
     * request handling. At that point $_SERVER['argv'] holds the `php server.php …`
     * invocation args. In worker coroutines a framework may overwrite $_SERVER with
     * per-request values; the checks below are defensive so re-entry from that
     * context falls back to SUMMARY (safe) rather than mis-detecting.
     *
     * For HTTP/Swoole deployments, prefer BOOT_VERBOSE=1 in the env — it is the
     * only mechanism that works identically across CLI, Swoole master, Swoole
     * workers, and FPM.
     */
    private static function detectVerbosity(): int
    {
        $env = getenv('BOOT_VERBOSE');
        if ($env !== false) {
            $value = strtolower(trim((string) $env));
            if (in_array($value, ['1', 'true', 'yes', 'on', 'verbose'], true)) {
                return self::VERBOSITY_VERBOSE;
            }
            if (in_array($value, ['0', 'false', 'no', 'off', 'silent'], true)) {
                return self::VERBOSITY_SILENT;
            }
        }

        if (PHP_SAPI !== 'cli') {
            return self::VERBOSITY_SUMMARY;
        }

        // Prefer $_SERVER['argv'] but fall back to $GLOBALS['argv'] — Swoole
        // workers may reset $_SERVER per request while $GLOBALS['argv'] remains
        // the process-level CLI argv inherited from master across fork.
        $argv = null;
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            $argv = $_SERVER['argv'];
        } elseif (isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
            $argv = $GLOBALS['argv'];
        }
        if ($argv === null) {
            return self::VERBOSITY_SUMMARY;
        }

        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (in_array($arg, ['-v', '-vv', '-vvv', '--verbose'], true)) {
                return self::VERBOSITY_VERBOSE;
            }
            if (in_array($arg, ['-q', '--quiet', '--silent'], true)) {
                return self::VERBOSITY_SILENT;
            }
        }

        return self::VERBOSITY_SUMMARY;
    }
}
