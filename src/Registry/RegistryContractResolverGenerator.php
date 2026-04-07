<?php

declare(strict_types=1);

namespace Semitexa\Core\Registry;

use ReflectionClass;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Generates contract resolver classes in src/Registry/Contracts/ when an interface
 * has 2+ implementations. Resolver receives all implementations via constructor (DI)
 * and exposes getContract() for the container to obtain the chosen implementation.
 *
 * When a "Factory" interface exists (same namespace, name starts with "Factory",
 * e.g. FactoryItemListProviderInterface for ItemListProviderInterface), generates
 * a factory class that implements it and allows choosing an implementation by enum key.
 */
class RegistryContractResolverGenerator
{
    public const REGISTRY_NAMESPACE = 'App\\Registry\\Contracts';
    public const REGISTRY_CONTRACTS_DIR = 'src/Registry/Contracts';

    /**
     * Generate resolver classes for interfaces with multiple implementations.
     *
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails from ServiceContractRegistry, filtered to count(implementations) >= 2
     * @return list<array{interface: string, resolver: string}>
     */
    public static function generateAll(array $contractDetails): array
    {
        $root = ProjectRoot::get();
        $outDir = $root . '/' . self::REGISTRY_CONTRACTS_DIR;
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $result = [];
        foreach ($contractDetails as $interface => $data) {
            $implementations = $data['implementations'] ?? [];
            $active = $data['active'] ?? null;
            if (count($implementations) < 2 || $active === null) {
                continue;
            }
            $resolverClass = self::writeResolverClass($root, $interface, $implementations, $active);
            if ($resolverClass !== null) {
                $result[] = ['interface' => $interface, 'resolver' => $resolverClass];
            }
        }
        return $result;
    }

    /**
     * Generate factory classes for contracts that have a Factory* interface (prefix convention).
     *
     * @param array<string, array{implementations: list<array{module: string, class: string}>, active: string}> $contractDetails multi-impl only
     * @return list<array{factoryInterface: string, factoryClass: string}>
     */
    public static function generateAllFactories(array $contractDetails): array
    {
        $root = ProjectRoot::get();
        $outDir = $root . '/' . self::REGISTRY_CONTRACTS_DIR;
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $result = [];
        foreach ($contractDetails as $baseInterface => $data) {
            $implementations = $data['implementations'] ?? [];
            if (count($implementations) < 2) {
                continue;
            }
            $factoryInterface = self::getFactoryInterfaceForContract($baseInterface);
            if ($factoryInterface === null || !interface_exists($factoryInterface)) {
                continue;
            }
            $factoryClass = self::writeFactoryClass($root, $baseInterface, $factoryInterface, $implementations);
            if ($factoryClass !== null) {
                $result[] = ['factoryInterface' => $factoryInterface, 'factoryClass' => $factoryClass];
            }
        }
        return $result;
    }

    /**
     * Convention: Factory* interface = same namespace as base contract, short name = "Factory" + base short name.
     */
    public static function getFactoryInterfaceForContract(string $baseContract): ?string
    {
        try {
            $ref = new ReflectionClass($baseContract);
        } catch (\Throwable $e) {
            return null;
        }
        $ns = $ref->getNamespaceName();
        $short = $ref->getShortName();
        return $ns . '\\Factory' . $short;
    }

    /**
     * Generated factory class FQN by convention: App\Registry\Contracts\{BaseShortName}Factory.
     */
    public static function getGeneratedFactoryClassForContract(string $baseContract): string
    {
        $ref = new ReflectionClass($baseContract);
        $short = $ref->getShortName();
        $baseShort = preg_replace('/Interface$/', '', $short);
        if ($baseShort === $short) {
            $baseShort = $short;
        }
        return self::REGISTRY_NAMESPACE . '\\' . $baseShort . 'Factory';
    }

    /**
     * @param list<array{module: string, class: string}> $implementations
     */
    private static function writeFactoryClass(string $root, string $baseInterface, string $factoryInterface, array $implementations): ?string
    {
        try {
            $baseRef = new ReflectionClass($baseInterface);
            $factoryRef = new ReflectionClass($factoryInterface);
        } catch (\Throwable $e) {
            return null;
        }
        $resolverShortName = preg_replace('/Interface$/', 'Resolver', $baseRef->getShortName());
        if ($resolverShortName === $baseRef->getShortName()) {
            $resolverShortName = $baseRef->getShortName() . 'Resolver';
        }
        $resolverClass = self::REGISTRY_NAMESPACE . '\\' . $resolverShortName;
        $factoryShortName = preg_replace('/Interface$/', '', $baseRef->getShortName());
        if ($factoryShortName === $baseRef->getShortName()) {
            $factoryShortName = $baseRef->getShortName();
        }
        $factoryShortName .= 'Factory';
        $outPath = $root . '/' . self::REGISTRY_CONTRACTS_DIR . '/' . $factoryShortName . '.php';

        /** @var array<string, string> $imports */
        $imports = [];
        /** @var array<string, string> $usedShortNames */
        $usedShortNames = [];
        $resolverTypeHint = self::addImport($resolverClass, $imports, $usedShortNames);
        $factoryInterfaceTypeHint = self::addImport($factoryInterface, $imports, $usedShortNames);
        $baseInterfaceTypeHint = self::addImport($baseInterface, $imports, $usedShortNames);
        $enumTypeHint = self::resolveFactoryEnumTypeHint($factoryRef, $imports, $usedShortNames);
        if ($enumTypeHint === null) {
            return null;
        }

        $params = ["        private {$resolverTypeHint} \$resolver,"];
        $paramNames = ['resolver'];
        $byKeyEntries = [];
        foreach ($implementations as $impl) {
            $implClass = $impl['class'];
            $module = $impl['module'];
            $shortClassName = (new ReflectionClass($implClass))->getShortName();
            $compositeKey = $module . '::' . $shortClassName;
            $typeHint = self::addImport($implClass, $imports, $usedShortNames);
            $paramName = self::uniqueParamName($implClass, $paramNames);
            $paramNames[] = $paramName;
            $params[] = "        private {$typeHint} \${$paramName},";
            $byKeyEntries[] = '            ' . var_export($compositeKey, true) . ' => $this->' . $paramName . ',';
        }
        $paramsStr = implode("\n", $params);
        $paramsStr = rtrim($paramsStr, ',');
        $byKeyStr = implode("\n", $byKeyEntries);

        $useBlock = self::formatUseBlock($imports);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Registry\Contracts;

{$useBlock}

/**
 * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:contracts
 * Factory for {$baseInterface}. Implements {$factoryInterface}. Enum-keyed and closed-world.
 */
final class {$factoryShortName} implements {$factoryInterfaceTypeHint}
{
    /** @var array<int|string, {$baseInterfaceTypeHint}> */
    private array \$byKey;

    public function __construct(
{$paramsStr}
    ) {
        \$this->byKey = [
{$byKeyStr}
        ];
    }

    public function getDefault(): {$baseInterfaceTypeHint}
    {
        return \$this->resolver->getContract();
    }

    public function get({$enumTypeHint} \$key): {$baseInterfaceTypeHint}
    {
        \$lookup = \$key->value;
        if (isset(\$this->byKey[\$lookup])) {
            return \$this->byKey[\$lookup];
        }
        throw new \InvalidArgumentException('Unknown implementation key: ' . \$key::class . '::' . \$key->name);
    }

    /** @return list<{$enumTypeHint}> */
    public function keys(): array
    {
        return array_map(
            static fn(int|string \$key): {$enumTypeHint} => {$enumTypeHint}::from(\$key),
            array_keys(\$this->byKey),
        );
    }
}

PHP;

        file_put_contents($outPath, $content);
        return self::REGISTRY_NAMESPACE . '\\' . $factoryShortName;
    }

    /**
     * @param ReflectionClass<object> $factoryRef
     * @param array<string, string> $imports
     * @param array<string, string> $usedShortNames
     */
    private static function resolveFactoryEnumTypeHint(ReflectionClass $factoryRef, array &$imports, array &$usedShortNames): ?string
    {
        if (!$factoryRef->hasMethod('get')) {
            return null;
        }

        $params = $factoryRef->getMethod('get')->getParameters();
        if (count($params) !== 1) {
            return null;
        }

        $type = $params[0]->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $enumClass = ltrim($type->getName(), '\\');
        if (!enum_exists($enumClass) || !is_subclass_of($enumClass, \BackedEnum::class)) {
            return null;
        }

        return self::addImport($enumClass, $imports, $usedShortNames);
    }


    /**
     * @param list<array{module: string, class: string}> $implementations
     */
    private static function writeResolverClass(string $root, string $interface, array $implementations, string $active): ?string
    {
        try {
            $ref = new ReflectionClass($interface);
        } catch (\Throwable $e) {
            return null;
        }
        $shortName = $ref->getShortName();
        $resolverShortName = preg_replace('/Interface$/', 'Resolver', $shortName);
        if ($resolverShortName === $shortName) {
            $resolverShortName = $shortName . 'Resolver';
        }
        $outPath = $root . '/' . self::REGISTRY_CONTRACTS_DIR . '/' . $resolverShortName . '.php';

        /** @var array<string, string> $imports */
        $imports = [];
        /** @var array<string, string> $usedShortNames */
        $usedShortNames = [];
        $params = [];
        $paramNames = [];
        foreach ($implementations as $impl) {
            $implClass = $impl['class'];
            $typeHint = self::addImport($implClass, $imports, $usedShortNames);
            $paramName = self::uniqueParamName($implClass, $paramNames);
            $paramNames[] = $paramName;
            $params[] = "        private {$typeHint} \${$paramName},";
        }
        $paramsStr = implode("\n", $params);
        $paramsStr = rtrim($paramsStr, ',');

        $activeParamName = '';
        foreach ($implementations as $i => $impl) {
            if ($impl['class'] === $active) {
                $activeParamName = $paramNames[$i];
                break;
            }
        }
        if ($activeParamName === '') {
            $activeParamName = $paramNames[0];
        }

        $interfaceTypeHint = self::addImport($interface, $imports, $usedShortNames);
        $useBlock = self::formatUseBlock($imports);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Registry\Contracts;

{$useBlock}

/**
 * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:contracts
 * Resolver for {$interface}.
 *
 * By default, the active implementation is selected automatically by module 'extends' priority.
 * Override getContract() only if you need to resolve a conflict between competing modules manually.
 */
final class {$resolverShortName}
{
    public function __construct(
{$paramsStr}
    ) {}

    public function getContract(): {$interfaceTypeHint}
    {
        return \$this->{$activeParamName};
    }
}

PHP;

        file_put_contents($outPath, $content);
        return self::REGISTRY_NAMESPACE . '\\' . $resolverShortName;
    }

    private static function uniqueParamName(string $class, array $used): string
    {
        $ref = new ReflectionClass($class);
        $short = $ref->getShortName();
        $name = lcfirst($short);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?: 'impl';
        $base = $name;
        $i = 0;
        while (in_array($name, $used, true)) {
            $i++;
            $name = $base . $i;
        }
        return $name;
    }

    /**
     * @param array<string, string> $imports
     * @param-out array<string, string> $imports
     * @param array<string, string> $usedShortNames
     * @param-out array<string, string> $usedShortNames
     */
    private static function addImport(string $fqn, array &$imports, array &$usedShortNames): string
    {
        $ref = new ReflectionClass($fqn);
        $short = $ref->getShortName();
        if (isset($usedShortNames[$short]) && $usedShortNames[$short] !== $fqn) {
            $alias = $short . 'Alias' . count($usedShortNames);
            $imports[$fqn] = $alias;
            return $alias;
        }
        $usedShortNames[$short] = $fqn;
        $imports[$fqn] = $short;
        return $short;
    }

    /**
     * @param array<string, string> $imports
     */
    private static function formatUseBlock(array $imports): string
    {
        $lines = [];
        foreach ($imports as $fqn => $alias) {
            $short = str_contains($fqn, '\\')
                ? substr($fqn, strrpos($fqn, '\\') + 1)
                : $fqn;
            $lines[] = "use {$fqn}" . ($alias !== $short ? " as {$alias}" : '') . ";";
        }
        return implode("\n", $lines);
    }
}
