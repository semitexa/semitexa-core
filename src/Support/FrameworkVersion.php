<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

use Composer\InstalledVersions;
use Semitexa\Core\Environment;

/**
 * Which Semitexa release is actually running.
 *
 * The release is the `semitexa/ultimate` version whose package set is
 * installed. Ultimate itself is not installed in an application — it is the
 * skeleton, and its `require` is the set — so the updater works out which
 * release vendor/ holds after every run and records it here
 * ({@see self::record()}). A cut re-tags only the packages that changed:
 * 2026.10.09.0714 moved dev, update and platform-ui and left core on
 * 2026.10.08.0620, and a footer reading core's tag kept naming the release
 * before.
 *
 * In order: `SEMITEXA_RELEASE_VERSION`, when a deployment publishes a
 * distribution version of its own; the recorded release; the installed
 * `semitexa/core` tag, for a project no updater has recorded yet.
 *
 * Only a real release ever comes back. A working tree resolves core as
 * `dev-develop` or a path repo, and printing that in a page footer would
 * advertise a version nobody can install; `null` means "no release to
 * name" and callers are expected to omit the line entirely.
 *
 * Static so a Twig extension can ask without the container having to hand it
 * a collaborator first. The record is re-read when the file changes: the
 * auto-deploy poller can record a release into workers that are not
 * restarted.
 */
class FrameworkVersion
{
    /** Where the updater records the installed release, relative to the project root. */
    public const RECORD_PATH = 'var/run/semitexa-release.json';

    private const PACKAGE = 'semitexa/core';

    /**
     * The release format the estate tags with: `YYYY.MM.DD.HHMM`, optionally
     * carrying a pre-release suffix.
     */
    private const RELEASE_PATTERN = '/^\d{4}\.\d{2}\.\d{2}\.\d{4}(?:-(?:alpha|beta|rc)\.\d+)?$/';

    private static ?string $fixed = null;

    private static bool $fixedResolved = false;

    private static ?string $recorded = null;

    /**
     * Identity of the record last read: inode, mtime and size, '' when there
     * was none. The inode changes on every record(), which renames a fresh
     * file into place, so two writes in the same second are still told apart.
     */
    private static ?string $recordKey = null;

    public static function current(): ?string
    {
        if (!self::$fixedResolved) {
            self::$fixedResolved = true;
            self::$fixed = self::release((string) (Environment::getEnvValue('SEMITEXA_RELEASE_VERSION', '') ?? ''));
        }

        return self::$fixed ?? self::recorded() ?? self::installed();
    }

    /**
     * Record the release that vendor/ now holds. Called by the updater; an
     * argument that is not a release version is refused rather than written.
     */
    public static function record(string $version, ?string $projectRoot = null): void
    {
        $release = self::release($version);
        if ($release === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a Semitexa release version.', $version));
        }

        $path = self::recordPath($projectRoot);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }

        $body = json_encode(
            ['version' => $release, 'package' => 'semitexa/ultimate', 'recorded_at' => gmdate('c')],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";

        // Rename over the old record so a worker never reads half a file.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot write %s.', $path));
        }
    }

    /**
     * Drop the record: vendor/ no longer holds any whole release, and naming
     * the last one would be untrue.
     */
    public static function forget(?string $projectRoot = null): void
    {
        $path = self::recordPath($projectRoot);
        // An absent record fails unlink() too; only a file that stays is an error.
        if (!@unlink($path) && file_exists($path)) {
            throw new \RuntimeException(sprintf('Cannot remove %s.', $path));
        }
    }

    /**
     * Drop the memoised answer. For tests that move the environment under it.
     */
    public static function reset(): void
    {
        self::$fixed = null;
        self::$fixedResolved = false;
        self::$recorded = null;
        self::$recordKey = null;
    }

    private static function recorded(): ?string
    {
        $path = self::recordPath(null);
        clearstatcache(true, $path);
        $stat = @stat($path);
        $key = $stat === false ? '' : $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['size'];
        if ($key === self::$recordKey) {
            return self::$recorded;
        }

        self::$recordKey = $key;
        self::$recorded = null;
        if ($stat === false) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (is_array($data) && is_string($data['version'] ?? null)) {
            self::$recorded = self::release($data['version']);
        }

        return self::$recorded;
    }

    private static function installed(): ?string
    {
        // Absent when the autoloader was not generated by Composer 2 — the
        // class is guarded rather than required so core keeps working in a
        // hand-wired build.
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled(self::PACKAGE)) {
            return null;
        }

        return self::release((string) (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? ''));
    }

    private static function recordPath(?string $projectRoot): string
    {
        return rtrim($projectRoot ?? ProjectRoot::get(), '/') . '/' . self::RECORD_PATH;
    }

    private static function release(string $candidate): ?string
    {
        $candidate = ltrim(trim($candidate), 'v');

        return preg_match(self::RELEASE_PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
