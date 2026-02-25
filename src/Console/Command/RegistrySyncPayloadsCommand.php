<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use ReflectionClass;
use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\AsPayloadPart;
use Semitexa\Core\Config\RegistryConfig;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Registry\RegistryPayloadGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Discover AsPayload and AsPayloadPart, generate PHP classes in src/registry/Payloads/.
 * When extra.semitexa.registry.use_manifest is true (default), reads/writes manifest.json.
 * When use_manifest is false (composer.json extra), uses only discovery and does not write manifest.
 */
class RegistrySyncPayloadsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('registry:sync:payloads')
            ->setDescription('Discover Payloads and PayloadParts, generate PHP classes in src/registry/Payloads/.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output manifest as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getProjectRoot();
        $asJson = (bool) $input->getOption('json');

        $this->ensureRegistryDirs($root);

        ClassDiscovery::initialize();
        ModuleRegistry::initialize();

        $payloads = $this->collectPayloads();
        $payloadParts = $this->collectPayloadParts();

        $useManifest = RegistryConfig::useManifest();
        if ($useManifest) {
            $existing = $this->loadExistingManifest($root);
            $payloads = $this->mergePayloads($payloads, $existing['payloads'] ?? []);
            $payloadParts = $this->mergePayloadParts($payloadParts, $existing['payload_parts'] ?? []);
        }

        $generated = RegistryPayloadGenerator::generateAll($payloads, $payloadParts);
        $manifest = [
            'version' => 1,
            'updated' => date('c'),
            'payloads' => $generated['payloads'],
            'payload_parts' => $generated['payload_parts'],
        ];

        if ($useManifest) {
            $manifestPath = $root . '/' . RegistryPayloadGenerator::REGISTRY_MANIFEST;
            $written = @file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
            );
            if ($written === false) {
                $output->writeln('<error>Failed to write manifest.</error>');
                return Command::FAILURE;
            }
        }

        if ($asJson) {
            $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $output->writeln($json !== false ? $json : '{}');
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->success('Registry payloads synced: ' . count($manifest['payloads']) . ' class(es) in src/registry/Payloads/.');
        return Command::SUCCESS;
    }

    private function ensureRegistryDirs(string $root): void
    {
        $dirs = [
            $root . '/src/registry',
            $root . '/src/registry/Payloads',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /** @return array<string, array{class: string, module: string, file: string, parts_order?: list<string>}> */
    private function collectPayloads(): array
    {
        $classes = array_filter(
            ClassDiscovery::findClassesWithAttribute(AsPayload::class),
            fn(string $class) => str_starts_with($class, 'Semitexa\\') && $this->isModuleActiveForClass($class)
        );
        $out = [];
        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }
            try {
                $ref = new ReflectionClass($className);
                if ($ref->getAttributes(AsPayload::class) === []) {
                    continue;
                }
                $out[$className] = [
                    'class' => $className,
                    'module' => $this->moduleNameForClass($className),
                    'file' => $ref->getFileName() ?: '',
                    'parts_order' => [],
                ];
            } catch (\Throwable $e) {
                // skip
            }
        }
        return $out;
    }

    /** @return array<string, array{class: string, base: string, module: string, file: string}> */
    private function collectPayloadParts(): array
    {
        $classes = array_filter(
            ClassDiscovery::findClassesWithAttribute(AsPayloadPart::class),
            fn(string $class) => str_starts_with($class, 'Semitexa\\') && $this->isModuleActiveForClass($class)
        );
        $out = [];
        foreach ($classes as $className) {
            if (!class_exists($className)) {
                continue;
            }
            try {
                $ref = new ReflectionClass($className);
                if (!$ref->isTrait()) {
                    continue;
                }
                foreach ($ref->getAttributes(AsPayloadPart::class) as $attr) {
                    $meta = $attr->newInstance();
                    $base = ltrim($meta->base, '\\');
                    $key = $className . "\0" . $base;
                    $out[$key] = [
                        'class' => $className,
                        'base' => $base,
                        'module' => $this->moduleNameForClass($className),
                        'file' => $ref->getFileName() ?: '',
                    ];
                }
            } catch (\Throwable $e) {
                // skip
            }
        }
        return $out;
    }

    private function isModuleActiveForClass(string $className): bool
    {
        $name = ModuleRegistry::getModuleNameForClass($className);
        return $name === null || ModuleRegistry::isActive($name);
    }

    private function moduleNameForClass(string $className): string
    {
        return ModuleRegistry::getModuleNameForClass($className) ?? 'Core';
    }

    /** @return array{payloads?: array, payload_parts?: array} */
    private function loadExistingManifest(string $root): array
    {
        $path = $root . '/' . RegistryPayloadGenerator::REGISTRY_MANIFEST;
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array{class: string, module: string, file: string, parts_order?: list<string>}> $discovered
     * @param array<int, array{class: string, module: string, file: string, parts_order?: list<string>}> $existing
     * @return array<int, array{class: string, module: string, file: string, parts_order?: list<string>}>
     */
    private function mergePayloads(array $discovered, array $existing): array
    {
        $byClass = [];
        foreach ($existing as $item) {
            $base = $item['base'] ?? $item['class'] ?? null;
            if ($base === null) {
                continue;
            }
            $byClass[$base] = [
                'class' => $base,
                'module' => $item['module'] ?? 'Core',
                'file' => $item['file'] ?? '',
                'parts_order' => $item['parts_order'] ?? [],
            ];
        }
        foreach ($discovered as $class => $item) {
            $existingItem = $byClass[$class] ?? null;
            $partsOrder = $existingItem['parts_order'] ?? $item['parts_order'] ?? [];
            $byClass[$class] = [
                'class' => $class,
                'module' => $item['module'],
                'file' => $item['file'],
                'parts_order' => $partsOrder,
            ];
        }
        return $byClass;
    }

    /**
     * @param array<string, array{class: string, base: string, module: string, file: string}> $discovered
     * @param array<string, array{class: string, base: string, module: string, file: string}> $existing
     * @return array<string, array{class: string, base: string, module: string, file: string}>
     */
    private function mergePayloadParts(array $discovered, array $existing): array
    {
        $byKey = [];
        foreach ($existing as $item) {
            $class = $item['class'] ?? null;
            $base = $item['base'] ?? null;
            if ($class !== null && $base !== null) {
                $key = $class . "\0" . $base;
                $byKey[$key] = [
                    'class' => $class,
                    'base' => $base,
                    'module' => $item['module'] ?? 'Core',
                    'file' => $item['file'] ?? '',
                ];
            }
        }
        foreach ($discovered as $key => $item) {
            $byKey[$key] = $item;
        }
        return $byKey;
    }
}
