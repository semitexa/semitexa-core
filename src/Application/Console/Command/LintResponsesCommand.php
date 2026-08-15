<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\HttpResponse;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify no HttpResponse construction or static factory usage in application code.
 */
#[AsCommand(name: 'lint:responses', description: 'Verify no HttpResponse construction in application code')]
final class LintResponsesCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lint: HttpResponse Construction');

        $errors = [];
        $filesChecked = 0;

        $root = $this->getProjectRoot();

        // Scan application directories for HttpResponse usage
        $scanDirs = [
            $root . '/src/modules',
        ];

        // Also scan packages except semitexa-core's Http/ and Pipeline/
        $packagesDir = $root . '/packages';
        if (is_dir($packagesDir)) {
            $packageIterator = new \DirectoryIterator($packagesDir);
            foreach ($packageIterator as $pkg) {
                if ($pkg->isDot() || !$pkg->isDir()) {
                    continue;
                }
                $srcDir = $pkg->getPathname() . '/src';
                if (is_dir($srcDir)) {
                    $scanDirs[] = $srcDir;
                }
            }
        }

        foreach ($scanDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo) {
                    continue;
                }
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                // Separators normalized before matching, as ClassDiscovery does:
                // getPathname() yields backslashes on Windows and the exclusion
                // would silently stop applying there.
                $normalizedPath = str_replace('\\', '/', $path);

                // Skip test code. Packages are scanned through their src/ only,
                // so package tests were already out of scope; modules are scanned
                // whole, which pulled their tests in and made the rule
                // inconsistent between the two. A test that asserts envelope
                // behaviour has to construct an HttpResponse to assert against.
                if (preg_match('#/src/modules/[^/]+/tests/#', $normalizedPath)) {
                    continue;
                }

                // Skip allowed kernel / framework-infrastructure directories.
                // The rule is "user payload handlers must return ResourceInterface DTOs";
                // it is not a ban on the framework itself producing HttpResponse from
                // pipeline phases, lifecycle phases, exception mappers, or pre-handler
                // tenancy guards. These run *outside* the user-handler pipeline.
                // Every comparison below is separator-sensitive, so all of them
                // read $normalizedPath. Only the real filesystem path is used
                // for I/O further down.
                if (str_contains($normalizedPath, 'semitexa-core/src/Http/')
                    || str_contains($normalizedPath, 'semitexa-core/src/Application.php')
                    // any package's Pipeline/ directory — pre-handler middleware
                    || preg_match('#/packages/[^/]+/src/Pipeline/#', $normalizedPath)
                    // any package's Lifecycle/ directory — kernel lifecycle phases
                    || preg_match('#/packages/[^/]+/src/Lifecycle/#', $normalizedPath)
                    // tenancy error responders + pre-handler guards (not TypedHandler implementations)
                    || str_contains($normalizedPath, 'semitexa-tenancy/src/Application/Service/DefaultTenantErrorResponder.php')
                    || str_contains($normalizedPath, 'semitexa-tenancy/src/Application/Service/TenantRequiredGuard.php')
                ) {
                    continue;
                }

                $filesChecked++;
                $content = file_get_contents($path);

                // An exception mapper is sanctioned wherever it lives — the
                // interface CONTRACT returns HttpResponse, and a consumer
                // project may legitimately override the binding from a module
                // (child-module priority). The path allowlist above only knows
                // the framework's own mappers, so recognise the rest by what
                // the class DECLARES rather than where it sits — matched on
                // tokenizer-stripped source, so the interface name inside a
                // comment or a string cannot smuggle a file past the lint.
                // File-level scope is deliberate: PSR-4 autoloading already
                // holds this codebase to one class per file. (#100)
                if (preg_match('/\bimplements[^{;]*\bExceptionResponseMapperInterface\b/s', self::codeOnly($content))) {
                    continue;
                }

                // Check for HttpResponse:: static calls
                if (preg_match('/HttpResponse::(json|html|text|notFound|redirect)\s*\(/', $content)) {
                    $relativePath = str_replace($root . '/', '', $path);
                    $errors[] = "{$relativePath}: Contains HttpResponse:: static factory call. Handlers must return ResourceInterface DTOs.";
                }

                // Check for new HttpResponse(
                if (preg_match('/new\s+HttpResponse\s*\(/', $content)
                    && !str_contains($path, 'semitexa-core')) {
                    $relativePath = str_replace($root . '/', '', $path);
                    $errors[] = "{$relativePath}: Contains 'new HttpResponse('. Only kernel code may construct HttpResponse objects.";
                }
            }
        }

        if ($errors === []) {
            $io->success(sprintf('No forbidden HttpResponse construction found in %d files.', $filesChecked));
            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        $io->error(sprintf('%d violation(s) found.', count($errors)));
        return self::FAILURE;
    }

    /**
     * The file's source with comments and string literals blanked out, so
     * declaration-level regexes cannot be satisfied by prose or data.
     */
    private static function codeOnly(string $content): string
    {
        $out = '';
        foreach (token_get_all($content) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }
            $out .= $text;
        }

        return $out;
    }
}
