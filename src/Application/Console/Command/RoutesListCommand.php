<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
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
    public function __construct(
        private readonly AttributeDiscovery $attributeDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('routes:list')
            ->setDescription('List all discovered routes with source information.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $routes = $this->attributeDiscovery->getRoutes();
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
                    'access' => self::stringifyAccessType($route['accessType'] ?? null),
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
                self::stringifyAccessType($route['accessType'] ?? null),
            ];
        }

        usort($tableRows, fn ($a, $b) => $a[2] <=> $b[2]);

        $io->table(
            ['#', 'Methods', 'Path', 'Name', 'Payload Class', 'Module', 'Access'],
            $tableRows
        );

        $io->info(count($routes) . ' route(s) discovered.');

        return Command::SUCCESS;
    }

    private function detectModule(string $className): string
    {
        return $this->moduleRegistry->getModuleNameForClass($className) ?? 'project';
    }

    private static function stringifyAccessType(mixed $value): string
    {
        if ($value instanceof \Semitexa\Core\Auth\PayloadAccessType) {
            return $value->value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return 'unknown';
    }
}
