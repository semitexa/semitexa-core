<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports handlers still using the deprecated HandlerInterface.
 * Use this command to track migration progress toward TypedHandlerInterface.
 */
#[AsCommand(
    name: 'semitexa:lint:handlers',
    description: 'Report handlers still using the deprecated HandlerInterface instead of TypedHandlerInterface.',
)]
class LintHandlersCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('semitexa:lint:handlers')
            ->setDescription('Report handlers still using the deprecated HandlerInterface instead of TypedHandlerInterface.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit with failure code if legacy handlers found');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $json = (bool) $input->getOption('json');
        $strict = (bool) $input->getOption('strict');

        ClassDiscovery::initialize();
        $classMap = ClassDiscovery::getClassMap();

        $legacy = [];
        $typed = [];

        foreach ($classMap as $className => $filePath) {
            try {
                if (!class_exists($className, true)) {
                    continue;
                }

                $ref = new \ReflectionClass($className);

                if ($ref->isAbstract() || $ref->isInterface()) {
                    continue;
                }

                if ($ref->implementsInterface(TypedHandlerInterface::class)) {
                    $typed[] = $className;
                } elseif ($ref->implementsInterface(HandlerInterface::class)) {
                    $legacy[] = [
                        'class' => $className,
                        'file' => $filePath,
                    ];
                }
            } catch (\Throwable) {
                // Skip classes that cannot be reflected (missing dependencies, etc.)
                continue;
            }
        }

        $totalHandlers = count($typed) + count($legacy);

        if ($json) {
            $this->outputJson($output, $legacy, $typed, $totalHandlers);
        } else {
            $this->outputTable($io, $legacy, $typed, $totalHandlers);
        }

        if ($strict && count($legacy) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function outputTable(SymfonyStyle $io, array $legacy, array $typed, int $total): void
    {
        $io->title('Handler Migration Report');

        if (count($legacy) === 0) {
            $io->success("All {$total} handlers use TypedHandlerInterface. Migration complete!");
            return;
        }

        $io->text(sprintf(
            'Progress: <info>%d</info>/%d handlers migrated (<comment>%d legacy remaining</comment>)',
            count($typed),
            $total,
            count($legacy),
        ));
        $io->newLine();

        $rows = [];
        foreach ($legacy as $item) {
            $rows[] = [
                $this->shortClass($item['class']),
                $this->shortenPath($item['file']),
            ];
        }

        $io->table(['Handler', 'File'], $rows);

        $io->warning(sprintf(
            '%d handler(s) still use the deprecated HandlerInterface. '
            . 'Migrate to TypedHandlerInterface before v2.0.',
            count($legacy),
        ));
    }

    private function outputJson(OutputInterface $output, array $legacy, array $typed, int $total): void
    {
        $data = [
            'total' => $total,
            'typed' => count($typed),
            'legacy' => count($legacy),
            'migration_complete' => count($legacy) === 0,
            'legacy_handlers' => array_map(fn ($item) => [
                'class' => $item['class'],
                'file' => $item['file'],
            ], $legacy),
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $output->writeln($json !== false ? $json : '{}');
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    private function shortenPath(string $path): string
    {
        $root = $this->getProjectRoot();
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root) + 1);
        }
        return $path;
    }
}
