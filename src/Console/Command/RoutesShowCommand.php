<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'routes:show', description: 'Show detailed debug information for a specific route.')]
class RoutesShowCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('routes:show')
            ->setDescription('Show detailed debug information for a specific route.')
            ->addArgument('id', InputArgument::REQUIRED, 'Route index (from routes:list) or path (e.g. /api/users)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        AttributeDiscovery::initialize();

        $id = $input->getArgument('id');
        $routes = AttributeDiscovery::getRoutes();
        $route = $this->findRoute($routes, $id);

        if ($route === null) {
            $io = new SymfonyStyle($input, $output);
            $io->error("Route not found: {$id}");
            $io->note('Use routes:list to see all available routes and their indices.');
            return Command::FAILURE;
        }

        $enriched = $this->buildDebugInfo($route);
        $asJson = (bool) $input->getOption('json');

        if ($asJson) {
            $output->writeln(json_encode($enriched, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $this->renderHuman(new SymfonyStyle($input, $output), $enriched);
        return Command::SUCCESS;
    }

    private function findRoute(array $routes, string $id): ?array
    {
        // Try numeric index first
        if (ctype_digit($id) && isset($routes[(int) $id])) {
            return $routes[(int) $id];
        }

        // Try exact path match
        foreach ($routes as $route) {
            if (($route['path'] ?? '') === $id) {
                return $route;
            }
        }

        // Try route name match
        foreach ($routes as $route) {
            if (($route['name'] ?? '') === $id) {
                return $route;
            }
        }

        return null;
    }

    private function buildDebugInfo(array $route): array
    {
        $payloadClass = $route['class'] ?? '';
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];

        // Enrich with handlers and response via findRoute
        $enriched = null;
        if (!empty($route['path'])) {
            foreach ($methods as $method) {
                $enriched = AttributeDiscovery::findRoute($route['path'], $method);
                if ($enriched !== null) {
                    break;
                }
            }
        }

        $responseClass = $enriched['responseClass'] ?? null;
        $handlers = $enriched['handlers'] ?? [];
        $responseAttrs = $responseClass ? AttributeDiscovery::getResolvedResponseAttributes($responseClass) : null;

        $info = [
            'path' => $route['path'] ?? '',
            'methods' => $methods,
            'name' => $route['name'] ?? null,
            'public' => $route['public'] ?? true,
            'type' => $route['type'] ?? null,
            'payload' => [
                'class' => $payloadClass,
                'module' => ModuleRegistry::getModuleNameForClass($payloadClass) ?? 'project',
                'file' => $this->resolveFile($payloadClass),
            ],
            'resource' => null,
            'handlers' => [],
            'requirements' => $route['requirements'] ?? [],
            'defaults' => $route['defaults'] ?? [],
            'options' => $route['options'] ?? [],
            'tags' => $route['tags'] ?? [],
        ];

        if ($responseClass) {
            $info['resource'] = [
                'class' => $responseClass,
                'module' => ModuleRegistry::getModuleNameForClass($responseClass) ?? 'project',
                'file' => $this->resolveFile($responseClass),
                'handle' => $responseAttrs['handle'] ?? null,
                'format' => $responseAttrs['format'] ?? null,
                'renderer' => $responseAttrs['renderer'] ?? null,
            ];
        }

        usort($handlers, fn ($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
        foreach ($handlers as $h) {
            $info['handlers'][] = [
                'class' => $h['class'],
                'module' => ModuleRegistry::getModuleNameForClass($h['class']) ?? 'project',
                'execution' => $h['execution'] ?? 'sync',
                'priority' => $h['priority'] ?? 0,
                'transport' => $h['transport'] ?? null,
                'queue' => $h['queue'] ?? null,
                'maxRetries' => $h['maxRetries'] ?? null,
                'retryDelay' => $h['retryDelay'] ?? null,
            ];
        }

        return $info;
    }

    private function renderHuman(SymfonyStyle $io, array $info): void
    {
        $io->title($info['path']);

        $io->section('Route');
        $io->definitionList(
            ['Methods' => implode(', ', $info['methods'])],
            ['Path' => $info['path']],
            ['Name' => $info['name'] ?? '-'],
            ['Public' => $info['public'] ? 'yes' : 'no'],
        );

        $io->section('Payload');
        $io->definitionList(
            ['Class' => $info['payload']['class']],
            ['Module' => $info['payload']['module']],
            ['File' => $info['payload']['file'] ?? '-'],
        );

        if ($info['resource']) {
            $io->section('Resource');
            $rows = [
                ['Class' => $info['resource']['class']],
                ['Module' => $info['resource']['module']],
                ['File' => $info['resource']['file'] ?? '-'],
            ];
            if ($info['resource']['handle'] !== null) {
                $rows[] = ['Handle' => $info['resource']['handle']];
            }
            if ($info['resource']['format'] !== null) {
                $format = $info['resource']['format'];
                $rows[] = ['Format' => is_object($format) ? $format->value : (string) $format];
            }
            if ($info['resource']['renderer'] !== null) {
                $rows[] = ['Renderer' => $info['resource']['renderer']];
            }
            $io->definitionList(...$rows);
        }

        if (!empty($info['handlers'])) {
            $io->section('Handlers (' . count($info['handlers']) . ')');
            $tableRows = [];
            foreach ($info['handlers'] as $h) {
                $extra = [];
                if ($h['transport']) {
                    $extra[] = "transport: {$h['transport']}";
                }
                if ($h['queue']) {
                    $extra[] = "queue: {$h['queue']}";
                }
                if ($h['maxRetries'] !== null) {
                    $extra[] = "retries: {$h['maxRetries']}";
                }
                $tableRows[] = [
                    $h['class'],
                    $h['module'],
                    $h['execution'],
                    (string) $h['priority'],
                    $extra ? implode(', ', $extra) : '-',
                ];
            }
            $io->table(['Class', 'Module', 'Execution', 'Priority', 'Extra'], $tableRows);
        } else {
            $io->section('Handlers');
            $io->text('No handlers registered.');
        }

        if (!empty($info['requirements'])) {
            $io->section('Requirements');
            $io->definitionList(...array_map(
                fn ($k, $v) => [$k => $v],
                array_keys($info['requirements']),
                array_values($info['requirements'])
            ));
        }

        if (!empty($info['defaults'])) {
            $io->section('Defaults');
            $io->definitionList(...array_map(
                fn ($k, $v) => [$k => is_string($v) ? $v : json_encode($v)],
                array_keys($info['defaults']),
                array_values($info['defaults'])
            ));
        }

        if (!empty($info['tags'])) {
            $io->section('Tags');
            $io->listing($info['tags']);
        }
    }

    private function resolveFile(string $className): ?string
    {
        try {
            $file = (new \ReflectionClass($className))->getFileName();
            return $file !== false ? $file : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
