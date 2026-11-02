<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Application\Service\RouteDebug\ClassLocation;
use Semitexa\Core\Application\Service\RouteDebug\RouteDebugInfo;
use Semitexa\Core\Application\Service\RouteDebug\RouteHandlerInfo;
use Semitexa\Core\Application\Service\RouteDebug\RouteResourceInfo;
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
    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected ModuleRegistry $moduleRegistry;

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

        $info = $this->buildDebugInfo($route);

        if ((bool) $input->getOption('json')) {
            $output->writeln(json_encode($info->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $this->renderHuman(new SymfonyStyle($input, $output), $info);
        return Command::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>> $routes
     * @return array<string, mixed>|null
     */
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

    /**
     * @param array<string, mixed> $route
     */
    private function buildDebugInfo(array $route): RouteDebugInfo
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

        $responseClass = is_string($enriched['responseClass'] ?? null) && $enriched['responseClass'] !== '' ? $enriched['responseClass'] : null;
        $handlersRaw = $enriched['handlers'] ?? [];
        $handlers = [];
        foreach (is_array($handlersRaw) ? $handlersRaw : [] as $handler) {
            if (is_array($handler) && is_string($handler['class'] ?? null) && $handler['class'] !== '') {
                $handlers[] = new RouteHandlerInfo(
                    class: $handler['class'],
                    module: $this->moduleOf($handler['class']),
                    execution: is_string($handler['execution'] ?? null) ? $handler['execution'] : 'sync',
                    priority: self::intOrNull($handler['priority'] ?? null) ?? 0,
                    transport: is_string($handler['transport'] ?? null) ? $handler['transport'] : null,
                    queue: is_string($handler['queue'] ?? null) ? $handler['queue'] : null,
                    maxRetries: self::intOrNull($handler['maxRetries'] ?? null),
                    retryDelay: self::intOrNull($handler['retryDelay'] ?? null),
                );
            }
        }
        usort($handlers, static fn (RouteHandlerInfo $a, RouteHandlerInfo $b): int => $b->priority <=> $a->priority);

        $resource = null;
        if ($responseClass !== null) {
            $attrs = $this->attributeDiscovery->getResolvedResponseAttributes($responseClass) ?? [];
            $format = $attrs['format'] ?? null;
            $resource = new RouteResourceInfo(
                location: $this->locate($responseClass),
                handle: is_string($attrs['handle'] ?? null) ? $attrs['handle'] : null,
                format: match (true) {
                    $format instanceof \BackedEnum => (string) $format->value,
                    is_string($format) => $format,
                    default => null,
                },
                renderer: is_string($attrs['renderer'] ?? null) ? $attrs['renderer'] : null,
            );
        }

        return new RouteDebugInfo(
            path: $routePath,
            methods: $methods,
            name: is_string($route['name'] ?? null) ? $route['name'] : null,
            accessType: $this->stringifyAccessType($route['accessType'] ?? null),
            type: is_string($route['type'] ?? null) ? $route['type'] : null,
            payload: $this->locate($payloadClass),
            resource: $resource,
            handlers: $handlers,
            requirements: self::map($route['requirements'] ?? null),
            defaults: self::map($route['defaults'] ?? null),
            options: self::map($route['options'] ?? null),
            tags: array_values(array_filter(is_array($route['tags'] ?? null) ? $route['tags'] : [], 'is_string')),
        );
    }

    private function renderHuman(SymfonyStyle $io, RouteDebugInfo $info): void
    {
        $io->title($info->path);

        $io->section('Route');
        $io->definitionList(
            ['Methods' => implode(', ', $info->methods)],
            ['Path' => $info->path],
            ['Name' => $info->name ?? '-'],
            ['Access' => $info->accessType],
        );

        $io->section('Payload');
        $io->definitionList(
            ['Class' => $info->payload->class],
            ['Module' => $info->payload->module],
            ['File' => $info->payload->file ?? '-'],
        );

        if ($info->resource !== null) {
            $io->section('Resource');
            $rows = [
                ['Class' => $info->resource->location->class],
                ['Module' => $info->resource->location->module],
                ['File' => $info->resource->location->file ?? '-'],
            ];
            foreach (['Handle' => $info->resource->handle, 'Format' => $info->resource->format, 'Renderer' => $info->resource->renderer] as $label => $value) {
                if ($value !== null) {
                    $rows[] = [$label => $value];
                }
            }
            $io->definitionList(...$rows);
        }

        if ($info->handlers !== []) {
            $io->section('Handlers (' . count($info->handlers) . ')');
            $tableRows = [];
            foreach ($info->handlers as $h) {
                $extra = [];
                if ($h->transport !== null && $h->transport !== '') {
                    $extra[] = 'transport: ' . $h->transport;
                }
                if ($h->queue !== null && $h->queue !== '') {
                    $extra[] = 'queue: ' . $h->queue;
                }
                if ($h->maxRetries !== null) {
                    $extra[] = 'retries: ' . $h->maxRetries;
                }
                $tableRows[] = [$h->class, $h->module, $h->execution, (string) $h->priority, $extra !== [] ? implode(', ', $extra) : '-'];
            }
            $io->table(['Class', 'Module', 'Execution', 'Priority', 'Extra'], $tableRows);
        } else {
            $io->section('Handlers');
            $io->text('No handlers registered.');
        }

        if ($info->requirements !== []) {
            $io->section('Requirements');
            $io->definitionList(...self::definitions($info->requirements));
        }

        if ($info->defaults !== []) {
            $io->section('Defaults');
            $io->definitionList(...self::definitions($info->defaults));
        }

        if ($info->tags !== []) {
            $io->section('Tags');
            $io->listing($info->tags);
        }
    }

    private function locate(string $class): ClassLocation
    {
        return new ClassLocation($class, $this->moduleOf($class), $this->resolveFile($class));
    }

    private function moduleOf(string $class): string
    {
        return $this->moduleRegistry->getModuleNameForClass($class) ?? 'project';
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        $map = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * One definition-list row per entry; a value that is not text is shown as JSON.
     *
     * @param array<string, mixed> $map
     * @return list<array<string, string>>
     */
    private static function definitions(array $map): array
    {
        $rows = [];
        foreach ($map as $key => $value) {
            $rows[] = [$key => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
        }

        return $rows;
    }

    private function resolveFile(string $className): ?string
    {
        if ($className === '' || (!class_exists($className) && !interface_exists($className))) {
            return null;
        }
        try {
            $file = (new \ReflectionClass($className))->getFileName();
            return $file !== false ? $file : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringifyAccessType(mixed $value): string
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
