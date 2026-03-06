<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'routes:list', description: 'List all discovered routes with source information.')]
class RoutesListCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('routes:list')
            ->setDescription('List all discovered routes with source information.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        AttributeDiscovery::initialize();

        $routes = AttributeDiscovery::getRoutes();
        $asJson = (bool) $input->getOption('json');

        if ($asJson) {
            $rows = [];
            foreach ($routes as $route) {
                $rows[] = [
                    'path' => $route['path'] ?? '',
                    'methods' => $route['methods'] ?? [$route['method'] ?? 'GET'],
                    'name' => $route['name'] ?? null,
                    'class' => $route['class'] ?? '',
                    'module' => $this->detectModule($route['class'] ?? ''),
                    'public' => $route['public'] ?? true,
                ];
            }
            $output->writeln(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Discovered Routes');

        if (empty($routes)) {
            $io->warning('No routes discovered.');
            return Command::SUCCESS;
        }

        $tableRows = [];
        foreach ($routes as $index => $route) {
            $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
            $tableRows[] = [
                (string) $index,
                implode('|', $methods),
                $route['path'] ?? '',
                $route['name'] ?? '-',
                $route['class'] ?? '',
                $this->detectModule($route['class'] ?? ''),
                ($route['public'] ?? true) ? 'yes' : 'no',
            ];
        }

        usort($tableRows, fn ($a, $b) => $a[2] <=> $b[2]);

        $io->table(
            ['#', 'Methods', 'Path', 'Name', 'Payload Class', 'Module', 'Public'],
            $tableRows
        );

        $io->info(count($routes) . ' route(s) discovered.');

        return Command::SUCCESS;
    }

    private function detectModule(string $className): string
    {
        return ModuleRegistry::getModuleNameForClass($className) ?? 'project';
    }
}
