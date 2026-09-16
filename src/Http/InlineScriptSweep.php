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
    private const EXTENSIONS = ['php', 'twig', 'html'];

    /** Directory names that never reach a browser. */
    private const SKIP_DIRS = ['vendor', 'node_modules', 'var', '.git', 'tests', 'Tests'];

    public function __construct(
        private readonly InlineScriptScanner $scanner = new InlineScriptScanner(),
    ) {}

    /**
     * @param list<string> $roots absolute paths; missing ones are skipped
     *
     * @return list<InlineScriptFinding>
     */
    public function sweep(string $projectRoot, array $roots): array
    {
        $findings = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach ($this->files($root) as $absolute) {
                $contents = @file_get_contents($absolute);
                // Case-insensitively: the scanner's own rule is, and a prefilter
                // stricter than the rule it guards drops files silently.
                if ($contents === false || stripos($contents, '<script') === false) {
                    continue;
                }

                $relative = $this->relativize($projectRoot, $absolute);

                foreach ($this->scanner->scan($relative, $contents, self::ownerOf($relative)) as $finding) {
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
     * A file under `packages/semitexa-*` is the framework's own emission: no
     * consumer can fix it, so it blocks. Everything else belongs to the
     * project reading the report.
     */
    public static function ownerOf(string $relativePath): InlineScriptOwner
    {
        return str_starts_with($relativePath, 'packages/semitexa-')
            ? InlineScriptOwner::Framework
            : InlineScriptOwner::Application;
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
