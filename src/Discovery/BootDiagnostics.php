<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

final class BootDiagnostics
{
    private static ?self $current = null;

    /** @var list<BootWarning> */
    private array $warnings = [];

    public static function begin(): self
    {
        self::$current = new self();
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

    /**
     * Call after build completes. Logs summary.
     * In strict mode, InvalidUsage warnings become fatal.
     */
    public function finalize(bool $strict = false): void
    {
        if ($this->warnings === []) {
            return;
        }

        $skips = array_filter($this->warnings, fn(BootWarning $w) => $w->severity === BootSeverity::Skip);
        $invalid = array_filter($this->warnings, fn(BootWarning $w) => $w->severity === BootSeverity::InvalidUsage);

        $summary = sprintf(
            "Boot completed: %d skip(s), %d invalid usage(s)\n",
            count($skips),
            count($invalid),
        );
        foreach ($this->warnings as $w) {
            $summary .= sprintf("  [%s][%s] %s\n", $w->severity->value, $w->component, $w->message);
        }

        if ($strict && $invalid !== []) {
            throw new BootException($summary);
        }

        error_log($summary);
    }

    /** @return list<BootWarning> */
    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
