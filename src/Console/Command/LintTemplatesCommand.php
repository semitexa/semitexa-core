<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates template directory structure across all modules.
 *
 * Errors (always blocking in --strict mode, blocks CI):
 *   - Legacy `themes/` directory inside Application/View/ (must migrate to templates/layouts/)
 *
 * Warnings (informational; blocking only with --strict):
 *   - Non-canonical subdirectory names inside templates/
 *
 * Canonical subdirectory names: pages/, layouts/, partials/, components/, deferred/
 */
#[AsCommand(
    name: 'semitexa:lint:templates',
    description: 'Validate template directory conventions across all modules.',
)]
class LintTemplatesCommand extends BaseCommand
{
    private const CANONICAL_SUBDIRS = ['pages', 'layouts', 'partials', 'components', 'deferred'];

    private const SUGGESTED_RENAMES = [
        'blocks'   => 'components',
        'block'    => 'partials',
        'frame'    => 'layouts',
        'frames'   => 'layouts',
        'views'    => 'pages',
        'page'     => 'pages',
        'layout'   => 'layouts',
        'partial'  => 'partials',
        'shared'   => 'partials',
        'includes' => 'partials',
        'sections' => 'partials',
        'slots'    => 'deferred',
    ];

    public function __construct(
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('semitexa:lint:templates')
            ->setDescription('Validate template directory conventions across all modules.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with failure code on warnings too')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $strict = (bool) $input->getOption('strict');
        $json   = (bool) $input->getOption('json');

        $errors   = [];
        $warnings = [];

        $this->scanLocalModules($errors, $warnings);
        $this->scanPackageModules($errors, $warnings);

        if ($json) {
            $this->outputJson($output, $errors, $warnings);
        } else {
            $this->outputTable($io, $errors, $warnings);
        }

        if (count($errors) > 0) {
            return Command::FAILURE;
        }

        if ($strict && count($warnings) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function scanLocalModules(array &$errors, array &$warnings): void
    {
        $modulesRoot = ProjectRoot::get() . '/src/modules';

        if (!is_dir($modulesRoot)) {
            return;
        }

        foreach (glob($modulesRoot . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);
            $this->scanModuleDir($module, $moduleDir, $errors, $warnings);
        }
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function scanPackageModules(array &$errors, array &$warnings): void
    {
        $modules = $this->moduleRegistry->getModules();

        foreach ($modules as $module) {
            $moduleName = $module['name'] ?? '';
            $modulePath = $module['path'] ?? '';
            $type       = $module['type'] ?? '';

            if ($type === 'local' || $moduleName === '' || $modulePath === '') {
                continue;
            }

            // Packages have src/ between module root and Application/
            $appDir = is_dir($modulePath . '/src') ? $modulePath . '/src' : $modulePath;
            $this->scanModuleDir($moduleName, $appDir, $errors, $warnings);
        }
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function scanModuleDir(string $module, string $moduleDir, array &$errors, array &$warnings): void
    {
        $projectRoot = ProjectRoot::get();

        // Error: legacy themes/ inside Application/View/
        $legacyThemesDir = $moduleDir . '/Application/View/themes';
        if (is_dir($legacyThemesDir)) {
            $rel = $this->relativePath($legacyThemesDir, $projectRoot);
            $errors[] = [
                'module'  => $module,
                'path'    => $rel,
                'message' => 'Legacy themes/ directory found. Migrate to Application/View/templates/layouts/.',
            ];
        }

        // Check templates/ subdirectories for non-canonical names
        $templatesDir = $moduleDir . '/Application/View/templates';
        if (!is_dir($templatesDir)) {
            return;
        }

        foreach (glob($templatesDir . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
            $name = basename($subdir);
            if (in_array($name, self::CANONICAL_SUBDIRS, true)) {
                continue;
            }

            $rel       = $this->relativePath($subdir, $projectRoot);
            $suggested = self::SUGGESTED_RENAMES[$name] ?? null;
            $hint      = $suggested !== null
                ? " Did you mean templates/{$suggested}/?"
                : ' Canonical names: ' . implode('/, ', self::CANONICAL_SUBDIRS) . '/';

            $warnings[] = [
                'module'  => $module,
                'path'    => $rel,
                'message' => "Non-canonical subdirectory '{$name}'.{$hint}",
            ];
        }
    }

    private function relativePath(string $path, string $root): string
    {
        $root = rtrim($root, '/\\');
        if ($path === $root) {
            return '.';
        }

        $prefix = $root . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function outputTable(SymfonyStyle $io, array $errors, array $warnings): void
    {
        $io->title('Template Directory Lint');

        if (count($errors) === 0 && count($warnings) === 0) {
            $io->success('All template directories follow canonical conventions.');
            return;
        }

        if (count($errors) > 0) {
            $rows = array_map(
                fn ($e) => [$e['module'], $e['path'], $e['message']],
                $errors
            );
            $io->section('Errors');
            $io->table(['Module', 'Path', 'Issue'], $rows);
        }

        if (count($warnings) > 0) {
            $rows = array_map(
                fn ($w) => [$w['module'], $w['path'], $w['message']],
                $warnings
            );
            $io->section('Warnings');
            $io->table(['Module', 'Path', 'Issue'], $rows);
        }

        if (count($errors) > 0) {
            $io->error(sprintf('%d error(s) found. Fix before merge.', count($errors)));
        } elseif (count($warnings) > 0) {
            $io->warning(sprintf('%d warning(s). Use --strict to block on warnings.', count($warnings)));
        }
    }

    /** @param array<int, array{module: string, path: string, message: string}> $errors
     *  @param array<int, array{module: string, path: string, message: string}> $warnings */
    private function outputJson(OutputInterface $output, array $errors, array $warnings): void
    {
        $data = [
            'errors'   => $errors,
            'warnings' => $warnings,
            'clean'    => count($errors) === 0 && count($warnings) === 0,
        ];

        $json = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $fallback = [
                'clean' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'json_error' => json_last_error_msg(),
            ];
            $json = json_encode($fallback, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $output->writeln($json !== false ? $json : '{"clean":false,"errors":[],"warnings":[],"json_error":"Failed to encode lint output"}');
    }
}
