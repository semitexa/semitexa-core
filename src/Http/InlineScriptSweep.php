<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * Walks a project tree and hands every emitting file to the scanner.
 *
 * Separate from the command so the traversal rules — which roots, which
 * extensions, who owns what — are testable against a directory rather than
 * asserted through console output.
 */
final class InlineScriptSweep
{
    // `htm` is here because the SCANNER treats it as markup, and a sweep that
    // walks a narrower set than the scanner classifies drops files silently:
    // a page.htm with a bare inline script was rejected before anything read
    // it, and the lint reported a clean tree.
    private const EXTENSIONS = ['php', 'twig', 'html', 'htm'];

    /** Directory names that never reach a browser. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'var', '.git', 'tests', 'Tests'];

    public function __construct(
        private readonly InlineScriptScanner $scanner = new InlineScriptScanner(),
    ) {}

    /**
     * @param list<string>        $roots absolute paths; missing ones are skipped
     * @param InlineScriptOwner|null $owner who owns everything under these
     *                                      roots, when the PATHS cannot say
     *
     * @return list<InlineScriptFinding>
     */
    public function sweep(string $projectRoot, array $roots, ?InlineScriptOwner $owner = null): array
    {
        $findings = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach ($this->files($root) as $absolute) {
                $contents = @file_get_contents($absolute);

                // An eligible file that cannot be READ is not a clean file.
                // Suppressing the failure and moving on is how this check
                // reports a green tree for a directory it never opened — the
                // exact silence it exists to remove. A permission or an I/O
                // error is the operator's to fix, and it says which file.
                if ($contents === false) {
                    throw new \RuntimeException(sprintf(
                        'lint:inline-script could not read %s. A file it cannot open is not a file it can clear.',
                        $this->relativize($projectRoot, $absolute)
                    ));
                }

                // Case-insensitively: the scanner's own rule is, and a prefilter
                // stricter than the rule it guards drops files silently.
                if (stripos($contents, '<script') === false) {
                    continue;
                }

                $relative = $this->relativize($projectRoot, $absolute);

                foreach ($this->scanner->scan($relative, $contents, $owner ?? self::ownerOf($relative)) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        usort(
            $findings,
            static fn (InlineScriptFinding $a, InlineScriptFinding $b): int
                => [$a->path, $a->line] <=> [$b->path, $b->line]
        );

        return $findings;
    }

    /**
     * Where the framework's own emissions live, in a workspace and in an
     * installed application.
     *
     * `vendor/semitexa/` is here because without it the check answers a
     * different question in the place it will mostly run: a consumer has no
     * `packages/` directory, so the sweep saw only their own code and reported
     * a clean tree however the installed packages behaved. A framework finding
     * there is still not theirs to patch — the remedy is an upgrade or a
     * written exemption — but it is theirs to KNOW.
     *
     * @var list<string>
     */
    private const FRAMEWORK_PREFIXES = ['packages/semitexa-', 'vendor/semitexa/'];

    /**
     * A file the framework emits: no consumer can edit it, so it blocks.
     * Everything else belongs to the project reading the report.
     *
     * The rule is the PATH, and outside those two prefixes it cannot tell a
     * framework tree from an application one — a package checked out on its
     * own has its code at `src/Http/…`, which is also exactly where a
     * consumer's own code lives. Guessing either way is wrong for the other,
     * so a caller that KNOWS says so: {@see self::sweep()} takes an owner.
     */
    public static function ownerOf(string $relativePath): InlineScriptOwner
    {
        foreach (self::FRAMEWORK_PREFIXES as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return InlineScriptOwner::Framework;
            }
        }

        return InlineScriptOwner::Application;
    }

    /** @return iterable<string> */
    private function files(string $root): iterable
    {
        $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $file): bool {
                if ($file->isDir()) {
                    return !in_array($file->getFilename(), self::SKIP_DIRS, true);
                }

                return in_array(strtolower($file->getExtension()), self::EXTENSIONS, true);
            }
        );

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }

    private function relativize(string $projectRoot, string $absolute): string
    {
        $prefix = rtrim($projectRoot, '/') . '/';

        return str_starts_with($absolute, $prefix)
            ? substr($absolute, strlen($prefix))
            : $absolute;
    }
}
