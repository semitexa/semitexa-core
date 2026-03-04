<?php

declare(strict_types=1);

namespace Semitexa\Core\Registry;

use ReflectionClass;
use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Attributes\AsResourcePart;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Http\Response\ResponseFormat;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\CodeExporter;
use Semitexa\Core\Util\CodeGenHelper;
use Semitexa\Core\Util\ProjectRoot;

/**
 * Generates PHP resource classes in src/registry/Resources/ that extend the original and use all AsResourcePart traits.
 * These generated classes are the single source for response/resource discovery (consistent with Payloads registry).
 */
class RegistryResourceGenerator
{
    public const REGISTRY_NAMESPACE = 'App\\Registry\\Resources';
    public const REGISTRY_RESOURCES_DIR = 'src/registry/Resources';
    public const REGISTRY_MANIFEST = 'src/registry/manifest.json';

    /** Order of AsResource constructor parameters for consistent generated output. */
    private const DEFAULT_ATTRIBUTE_ORDER = [
        'handle', 'doc', 'base', 'context', 'format', 'renderer',
    ];

    private static bool $bootstrapped = false;

    /** @var array<string, array{class: string, short: string, attr: AsResource, file: string, module: array}> */
    private static array $definitions = [];


    /**
     * Generate all registry resource classes and return manifest data.
     *
     * @param array<string, array{class: string, module: string, file: string, parts_order: list<string>}> $resources
     * @param array<string, array{class: string, base: string, module: string, file: string}> $resourceParts
     * @return array{resources: list<array>, resource_parts: list<array>}
     */
    public static function generateAll(array $resources, array $resourceParts): array
    {
        self::bootstrap();
        $root = ProjectRoot::get();
        $outDir = $root . '/' . self::REGISTRY_RESOURCES_DIR;
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $manifestResources = [];
        $manifestParts = [];

        foreach ($resources as $baseClass => $resourceMeta) {
            $partsOrder = $resourceMeta['parts_order'] ?? [];
            $parts = self::collectPartsForBase($baseClass, $resourceParts, $partsOrder);
            $target = self::$definitions[$baseClass] ?? null;
            if ($target === null) {
                continue;
            }
            self::writeResourceClass($root, $target, $parts);
            $manifestResources[] = [
                'class' => self::REGISTRY_NAMESPACE . '\\' . $target['short'],
                'base' => $baseClass,
                'module' => $resourceMeta['module'],
                'file' => $target['file'],
                'parts_order' => array_map(fn($p) => $p['trait'], $parts),
            ];
        }

        foreach ($resourceParts as $part) {
            $manifestParts[] = $part;
        }

        return ['resources' => $manifestResources, 'resource_parts' => $manifestParts];
    }

    private static function bootstrap(): void
    {
        if (self::$bootstrapped) {
            return;
        }
        ClassDiscovery::initialize();
        ModuleRegistry::initialize();
        self::$definitions = self::collectDefinitions();
        self::$bootstrapped = true;
    }

    private static function collectDefinitions(): array
    {
        $definitions = [];
        $classes = ClassDiscovery::findClassesWithAttribute(AsResource::class);
        foreach ($classes as $className) {
            try {
                $ref = new ReflectionClass($className);
                $attrs = $ref->getAttributes(AsResource::class);
                if ($attrs === []) {
                    continue;
                }
                $attr = $attrs[0]->newInstance();
                $definitions[$className] = [
                    'class' => $className,
                    'short' => $ref->getShortName(),
                    'attr' => $attr,
                    'file' => $ref->getFileName() ?: '',
                    'module' => CodeGenHelper::detectModule($ref->getFileName() ?: ''),
                ];
            } catch (\Throwable $e) {
                // skip
            }
        }
        return $definitions;
    }


    /**
     * @param array<string, array{class: string, base: string, module: string, file: string}> $resourceParts
     * @param list<string> $orderPreferred
     * @return list<array{trait: string, file: string}>
     */
    private static function collectPartsForBase(string $baseClass, array $resourceParts, array $orderPreferred): array
    {
        $byTrait = [];
        foreach ($resourceParts as $part) {
            if ($part['base'] === $baseClass) {
                $byTrait[$part['class']] = ['trait' => $part['class'], 'file' => $part['file']];
            }
        }
        $ordered = [];
        foreach ($orderPreferred as $trait) {
            $trait = ltrim($trait, '\\');
            if (isset($byTrait[$trait])) {
                $ordered[] = $byTrait[$trait];
                unset($byTrait[$trait]);
            }
        }
        foreach ($byTrait as $t) {
            $ordered[] = $t;
        }
        return $ordered;
    }

    /**
     * @param array{class: string, short: string, attr: AsResource, file: string, module: array} $target
     * @param list<array{trait: string, file: string}> $parts
     */
    private static function writeResourceClass(string $root, array $target, array $parts): void
    {
        /** @var AsResource $attr */
        $attr = $target['attr'];
        $baseClass = $target['class'];
        $shortName = $target['short'];
        $outPath = $root . '/' . self::REGISTRY_RESOURCES_DIR . '/' . $shortName . '.php';

        $imports = [];
        $used = [];
        try {
            $baseRef = new ReflectionClass($baseClass);
            if ($baseRef->isFinal()) {
                throw new \RuntimeException(
                    "Registry resource base class {$baseClass} is final. Module resources must not be final so registry can extend them (single source of truth: src/registry/Resources)."
                );
            }
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
        }

        $baseAlias = CodeGenHelper::registerImport($baseClass, $imports, $used, 'Base');
        $traitAliases = [];
        foreach ($parts as $p) {
            $traitAliases[] = CodeGenHelper::registerImport($p['trait'], $imports, $used);
        }

        $attrMap = [];
        if ($attr->handle !== null && $attr->handle !== '') {
            $attrMap['handle'] = "handle: " . CodeExporter::exportValue(EnvValueResolver::resolve($attr->handle));
        }
        if ($attr->context !== null && $attr->context !== []) {
            $attrMap['context'] = "context: " . CodeExporter::exportValue(EnvValueResolver::resolve($attr->context));
        }
        if ($attr->format !== null) {
            $formatVal = $attr->format instanceof ResponseFormat ? $attr->format->value : (string) $attr->format;
            $attrMap['format'] = "format: ResponseFormat::" . ucfirst(strtolower($formatVal));
        }
        if ($attr->renderer !== null && $attr->renderer !== '') {
            $attrMap['renderer'] = "renderer: " . CodeExporter::exportValue(EnvValueResolver::resolve($attr->renderer));
        }

        $attrLines = self::orderAttributeLines($attrMap, $outPath);
        $attrString = implode(",\n    ", $attrLines);
        $uniqueImports = [];
        $seen = [];
        foreach ($imports as $imp) {
            $key = $imp['fqn'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $uniqueImports[] = $imp;
        }
        $useLines = [];
        foreach ($uniqueImports as $imp) {
            $alias = $imp['alias'] !== $imp['short'] ? ' as ' . $imp['alias'] : '';
            $useLines[] = 'use ' . $imp['fqn'] . $alias . ';';
        }
        $useBlock = implode("\n", $useLines);

        $traitBlock = '';
        if ($traitAliases !== []) {
            $traitBlock = "\n" . implode("\n", array_map(fn($a) => "    use {$a};", $traitAliases)) . "\n";
        }

        $extendsLine = " extends {$baseAlias}";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Registry\Resources;

use Semitexa\Core\Attributes\AsResource;
use Semitexa\Core\Http\Response\ResponseFormat;
{$useBlock}

/**
 * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:resources
 */
#[AsResource(
    {$attrString}
)]
final class {$shortName}{$extendsLine}
{{$traitBlock}}

PHP;

        file_put_contents($outPath, $content);
    }

    /**
     * @param array<string, string> $attrMap key => line
     * @return list<string>
     */
    private static function orderAttributeLines(array $attrMap, string $outPath): array
    {
        $order = self::extractAttributeParamOrder($outPath);
        if ($order === []) {
            $order = self::DEFAULT_ATTRIBUTE_ORDER;
        }
        $result = [];
        foreach ($order as $key) {
            if (isset($attrMap[$key])) {
                $result[] = $attrMap[$key];
                unset($attrMap[$key]);
            }
        }
        foreach ($attrMap as $line) {
            $result[] = $line;
        }
        return $result;
    }

    /**
     * Parse existing file and return the order of parameter names inside #[AsResource(...)].
     * @return list<string>
     */
    private static function extractAttributeParamOrder(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }
        $needle = '#[AsResource(';
        $start = strpos($content, $needle);
        if ($start === false) {
            return [];
        }
        $pos = $start + strlen($needle);
        $depth = 1;
        $len = strlen($content);
        while ($pos < $len && $depth > 0) {
            $c = $content[$pos];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            $pos++;
        }
        if ($depth !== 0) {
            return [];
        }
        $inner = substr($content, $start + strlen($needle), $pos - $start - strlen($needle) - 1);
        $order = [];
        if (preg_match_all('/^\s*(\w+)\s*:/m', $inner, $matches)) {
            foreach ($matches[1] as $key) {
                $order[] = $key;
            }
        }
        return $order;
    }

}
