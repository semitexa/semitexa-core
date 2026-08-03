<?php

declare(strict_types=1);

namespace Semitexa\Core\Discovery;

use ReflectionClass;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Where a discovered class came from, and how much authority that origin carries.
 *
 * Discovery routinely finds several classes competing for the same route or the
 * same resource handle — a package ships one, a module overrides it, the project
 * overrides that. The winner is decided by origin, so "which file is this class
 * in" is a policy question, not a filesystem detail, and it belongs in one place.
 *
 * The ranking is deliberately coarse and ordered outermost-wins:
 *
 *   src/modules/  (400) — an installed module, closest to the running project
 *   src/          (300) — the project's own code
 *   packages/     (200) — a Semitexa package in a workspace checkout
 *   vendor/ etc.  (100) — anything else that was autoloadable
 *   unknown       (0)   — no file (eval'd / internal); never wins a tie
 *
 * Before ep-slay-attribute-discovery this lived in AttributeDiscovery as four
 * private statics, three of which — isProjectHandler, isProjectPayload and
 * isProjectResource — were byte-identical apart from the word in their
 * BootDiagnostics message. They are one method here, with the subject passed in.
 */
final class SourceOrigin
{
    public const PRIORITY_MODULE = 400;
    public const PRIORITY_PROJECT = 300;
    public const PRIORITY_PACKAGE = 200;
    public const PRIORITY_VENDOR = 100;
    public const PRIORITY_UNKNOWN = 0;

    /**
     * Rank a file by origin. Higher wins an override contest.
     */
    public function priorityForFile(string $file): int
    {
        if ($file === '') {
            return self::PRIORITY_UNKNOWN;
        }

        if (str_contains($file, '/src/modules/')) {
            return self::PRIORITY_MODULE;
        }

        if ($this->isProjectFile($file)) {
            return self::PRIORITY_PROJECT;
        }

        if (str_contains($file, '/packages/')) {
            return self::PRIORITY_PACKAGE;
        }

        return self::PRIORITY_VENDOR;
    }

    /**
     * Does this file live under the project's own src/ (including src/modules/)?
     */
    public function isProjectFile(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        return str_starts_with($file, ProjectRoot::get() . '/src/');
    }

    /**
     * Does this class live under the project's own src/?
     *
     * Discovery uses this to admit project code that no module claims. Reflection
     * on an autoloadable-but-broken class can throw anything, and a single bad
     * class must not abort a boot-wide scan — so a failure is recorded as a skip
     * and answered "not project", which is the conservative answer: the class is
     * simply not granted project authority.
     *
     * @param string $subject What is being classified ("handler", "payload",
     *                        "resource"), used only to make the skip readable.
     */
    public function isProjectClass(string $className, string $subject): bool
    {
        try {
            $file = (new ReflectionClass($className))->getFileName();

            return $file !== false && $this->isProjectFile($file);
        } catch (\Throwable $e) {
            BootDiagnostics::current()->skip(
                'AttributeDiscovery',
                "Project-origin check failed for {$subject} {$className}: " . $e->getMessage(),
                $e,
            );

            return false;
        }
    }
}
