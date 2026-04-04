<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
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
    public function __construct(
        private readonly AttributeDiscovery $attributeDiscovery,
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('routes:show')
            ->setDescription('Show detailed debug information for a specific route.')
            ->addArgument('id', InputArgument::REQUIRED, 'Route index (from routes:list) or path (e.g. /api/users)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawId = $input->getArgument('id');
        $id = is_scalar($rawId) || $rawId instanceof \Stringable ? (string) $rawId : '';
        $routes = $this->attributeDiscovery->getRoutes();
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
        $payloadClass = is_string($route['class'] ?? null) ? $route['class'] : '';
        $methods = array_values(array_filter(
            is_array($route['methods'] ?? null) ? $route['methods'] : [$route['method'] ?? 'GET'],
            static fn (mixed $method): bool => is_string($method) && $method !== '',
        ));
        if ($methods === []) {
            $methods = ['GET'];
        }

        // Enrich with handlers and response via findRoute
        $enriched = null;
        $routePath = is_string($route['path'] ?? null) ? $route['path'] : '';
        if ($routePath !== '') {
            foreach ($methods as $method) {
                $enriched = $this->attributeDiscovery->findRoute($routePath, $method);
                if ($enriched !== null) {
                    break;
                }
            }
        }

        $responseClass = is_string($enriched['responseClass'] ?? null) ? $enriched['responseClass'] : null;
        $handlers = $enriched['handlers'] ?? [];
        $responseAttrs = $responseClass ? $this->attributeDiscovery->getResolvedResponseAttributes($responseClass) : null;

        $info = [
            'path' => $routePath,
            'methods' => $methods,
            'name' => is_string($route['name'] ?? null) ? $route['name'] : null,
            'public' => $route['public'] ?? true,
            'type' => is_string($route['type'] ?? null) ? $route['type'] : null,
            'payload' => [
                'class' => $payloadClass,
                'module' => $this->moduleRegistry->getModuleNameForClass($payloadClass) ?? 'project',
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
                'module' => $this->moduleRegistry->getModuleNameForClass($responseClass) ?? 'project',
                'file' => $this->resolveFile($responseClass),
                'handle' => is_string($responseAttrs['handle'] ?? null) ? $responseAttrs['handle'] : null,
                'format' => $responseAttrs['format'] ?? null,
                'renderer' => is_string($responseAttrs['renderer'] ?? null) ? $responseAttrs['renderer'] : null,
            ];
        }

        $normalizedHandlers = [];
        foreach ($handlers as $handler) {
            if (!is_array($handler)) {
                continue;
            }

            $normalizedHandlers[] = $handler;
        }

        usort(
            $normalizedHandlers,
            static fn (array $a, array $b): int => self::handlerPriority($b) <=> self::handlerPriority($a)
        );

        foreach ($normalizedHandlers as $h) {
            $handlerClass = is_string($h['class'] ?? null) ? $h['class'] : '';
            if ($handlerClass === '') {
                continue;
            }
            $info['handlers'][] = [
                'class' => $handlerClass,
                'module' => $this->moduleRegistry->getModuleNameForClass($handlerClass) ?? 'project',
                'execution' => is_string($h['execution'] ?? null) ? $h['execution'] : 'sync',
                'priority' => $h['priority'] ?? 0,
                'transport' => is_string($h['transport'] ?? null) ? $h['transport'] : null,
                'queue' => is_string($h['queue'] ?? null) ? $h['queue'] : null,
                'maxRetries' => $h['maxRetries'] ?? null,
                'retryDelay' => $h['retryDelay'] ?? null,
            ];
        }

        return $info;
    }

    private static function handlerPriority(mixed $handler): int
    {
        if (!is_array($handler)) {
            return 0;
        }

        $priority = $handler['priority'] ?? 0;

        return is_int($priority) ? $priority : (is_numeric($priority) ? (int) $priority : 0);
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
