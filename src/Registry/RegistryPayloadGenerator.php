<?php

declare(strict_types=1);

namespace Semitexa\Core\Registry;

use ReflectionClass;
use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Attributes\AsPayloadPart;
use Semitexa\Core\Config\EnvValueResolver;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Util\CodeGenHelper;
use Semitexa\Core\Util\ProjectRoot;

/**
 * Generates PHP payload classes in src/registry/Payloads/ that extend the original and use all AsPayloadPart traits.
 * These generated classes are the single source for route discovery.
 */
class RegistryPayloadGenerator
{
    public const REGISTRY_NAMESPACE = 'App\\Registry\\Payloads';
    public const REGISTRY_PAYLOADS_DIR = 'src/registry/Payloads';
    public const REGISTRY_MANIFEST = 'src/registry/manifest.json';

    /** Order of AsPayload constructor parameters — matches Semitexa\Core\Attributes\AsPayload so IDE/style checks are happy. */
    private const DEFAULT_ATTRIBUTE_ORDER = [
        'base', 'overrides', 'responseWith', 'path', 'methods', 'name',
        'requirements', 'defaults', 'options', 'tags', 'public',
    ];

    private static bool $bootstrapped = false;

    /** @var array<string, array{class: string, short: string, attr: AsPayload, file: string, module: array}> */
    private static array $definitions = [];


    /**
     * Generate all registry payload classes and return manifest data.
     *
     * @param array<string, array{class: string, module: string, file: string, parts_order: list<string>}> $payloads
     * @param array<string, array{class: string, base: string, module: string, file: string}> $payloadParts
     * @return array{payloads: list<array>, payload_parts: list<array>}
     */
    public static function generateAll(array $payloads, array $payloadParts): array
    {
        self::bootstrap();
        $root = ProjectRoot::get();
        $outDir = $root . '/' . self::REGISTRY_PAYLOADS_DIR;
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $manifestPayloads = [];
        $manifestParts = [];

        foreach ($payloads as $baseClass => $payloadMeta) {
            $partsOrder = $payloadMeta['parts_order'] ?? [];
            $parts = self::collectPartsForBase($baseClass, $payloadParts, $partsOrder);
            $target = self::$definitions[$baseClass] ?? null;
            if ($target === null) {
                continue;
            }
            self::writePayloadClass($root, $target, $parts);
            $manifestPayloads[] = [
                'class' => self::REGISTRY_NAMESPACE . '\\' . $target['short'],
                'base' => $baseClass,
                'module' => $payloadMeta['module'],
                'file' => $target['file'],
                'parts_order' => array_map(fn($p) => $p['trait'], $parts),
            ];
        }

        foreach ($payloadParts as $key => $part) {
            $manifestParts[] = $part;
        }

        return ['payloads' => $manifestPayloads, 'payload_parts' => $manifestParts];
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
        $classes = ClassDiscovery::findClassesWithAttribute(AsPayload::class);
        foreach ($classes as $className) {
            try {
                $ref = new ReflectionClass($className);
                $attrs = $ref->getAttributes(AsPayload::class);
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
     * @param array<string, array{class: string, base: string, module: string, file: string}> $payloadParts
     * @param list<string> $orderPreferred
     * @return list<array{trait: string, file: string}>
     */
    private static function collectPartsForBase(string $baseClass, array $payloadParts, array $orderPreferred): array
    {
        $byTrait = [];
        foreach ($payloadParts as $part) {
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
     * @param array{class: string, short: string, attr: AsPayload, file: string, module: array} $target
     * @param list<array{trait: string, file: string}> $parts
     */
    private static function writePayloadClass(string $root, array $target, array $parts): void
    {
        /** @var AsPayload $attr */
        $attr = $target['attr'];
        $baseClass = $target['class'];
        $shortName = $target['short'];
        $outPath = $root . '/' . self::REGISTRY_PAYLOADS_DIR . '/' . $shortName . '.php';

        $imports = [];
        $used = [];
        $baseIsFinal = false;
        try {
            $baseIsFinal = (new ReflectionClass($baseClass))->isFinal();
        } catch (\Throwable $e) {
            // ignore
        }
        if ($baseIsFinal) {
            throw new \RuntimeException(
                "Registry payload base class {$baseClass} is final. Module payloads must not be final so registry can extend them (single source of truth: src/registry/Payloads)."
            );
        }
        $baseAlias = CodeGenHelper::registerImport($baseClass, $imports, $used, 'Base');
        $traitAliases = [];
        foreach ($parts as $p) {
            $traitAliases[] = CodeGenHelper::registerImport($p['trait'], $imports, $used);
        }

        $attrMap = [];
        $attrMap['path'] = "path: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->path));
        if ($attr->methods !== null && $attr->methods !== []) {
            $attrMap['methods'] = "methods: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->methods));
        }
        if ($attr->name !== null && $attr->name !== '') {
            $attrMap['name'] = "name: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->name));
        }
        if ($attr->responseWith !== null && $attr->responseWith !== '') {
            $responseClass = EnvValueResolver::resolve($attr->responseWith);
            $attrMap['responseWith'] = "responseWith: " . CodeGenHelper::registerImport($responseClass, $imports, $used) . "::class";
        }
        if ($attr->requirements !== null && $attr->requirements !== []) {
            $attrMap['requirements'] = "requirements: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->requirements));
        }
        if ($attr->defaults !== null && $attr->defaults !== []) {
            $attrMap['defaults'] = "defaults: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->defaults));
        }
        if ($attr->options !== null && $attr->options !== []) {
            $attrMap['options'] = "options: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->options));
        }
        if ($attr->tags !== null && $attr->tags !== []) {
            $attrMap['tags'] = "tags: " . CodeGenHelper::exportValue(EnvValueResolver::resolve($attr->tags));
        }
        if ($attr->public !== null) {
            $attrMap['public'] = "public: " . ($attr->public ? 'true' : 'false');
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
        $implementsLine = '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Registry\Payloads;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Contract\PayloadInterface;
{$useBlock}

/**
 * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:payloads
 */
#[AsPayload(
    {$attrString}
)]
final class {$shortName}{$extendsLine}{$implementsLine}
{{$traitBlock}}

PHP;

        file_put_contents($outPath, $content);
    }

    /**
     * Output attribute lines: existing file order (if present) or AsPayload declaration order, so IDE/style is happy.
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
     * Parse existing file and return the order of parameter names inside #[AsPayload(...)].
     * Preserves manual parameter order across registry:sync:payloads.
     * @return list<string> e.g. ['path', 'methods', 'base', 'overrides', 'responseWith'] or [] if unparseable
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
        $needle = '#[AsPayload(';
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
