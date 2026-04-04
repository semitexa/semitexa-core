<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

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
#[AsCommand(name: 'semitexa:lint:responses', description: 'Verify no HttpResponse construction in application code')]
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
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();

                // Skip allowed kernel directories
                if (str_contains($path, 'semitexa-core/src/Http/')
                    || str_contains($path, 'semitexa-core/src/Pipeline/')
                    || str_contains($path, 'semitexa-core/src/Application.php')) {
                    continue;
                }

                $filesChecked++;
                $content = file_get_contents($path);

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
}
