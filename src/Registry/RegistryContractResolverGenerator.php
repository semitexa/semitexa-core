<?php

declare(strict_types=1);

namespace Semitexa\Core\Registry;

use ReflectionClass;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Contract\ContractFactoryInterface;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The registry alone decides a contract's active implementation
 * (d-20261123-di-registry-one-source): the resolver classes this used to write in
 * the canonical contracts directory are no longer written, and removeGeneratedResolvers()
 * deletes the ones it wrote. An edit to their getContract() never survived the next
 * sync, which runs after every composer install or update, and the container
 * reached a resolver for one contract in fourteen.
 *
 * When a "Factory" interface exists (same namespace, name starts with "Factory",
 * e.g. FactoryItemListProviderInterface for ItemListProviderInterface), generates
 * a factory class that implements it and allows choosing an implementation by enum key.
 */
class RegistryContractResolverGenerator
{
    /** The first line of every class this generator writes. */
    public const GENERATED_HEADER = 'AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:contracts';

    /**
     * Delete the resolver classes this generator wrote: a file of the canonical
     * contracts directory named *Resolver.php that carries the generated header.
     * A file that does not is someone's own, and stays.
     *
     * @return list<string> the files removed, relative to the project root
     *
     * @throws \RuntimeException when a resolver cannot be read (it may be a
     *                           generated one) or a generated one cannot be removed:
     *                           a sync that left one behind must not report success
     */
    public static function removeGeneratedResolvers(): array
    {
        $root = ProjectRoot::get();
        $dir = $root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS;
        $removed = [];
        foreach (glob($dir . '/*Resolver.php') ?: [] as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                throw new \RuntimeException("Cannot read {$file}, so it cannot be told whether it is a generated resolver to remove.");
            }
            if (!str_contains($source, self::GENERATED_HEADER) || !str_contains($source, ' * Resolver for ')) {
                continue;
            }
            if (!@unlink($file)) {
                throw new \RuntimeException("Cannot remove {$file}, a generated resolver the container no longer reads.");
            }
            $removed[] = CanonicalRegistryPaths::REGISTRY_CONTRACTS . '/' . basename($file);
        }
        sort($removed);

        return $removed;
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
        $outDir = $root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS;
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
        return CanonicalRegistryPaths::REGISTRY_CONTRACTS_NAMESPACE . '\\' . $baseShort . 'Factory';
    }

    /**
     * The generated class adapts the container's generic ContractFactory to the
     * contract's Factory* interface (whose get() takes the concrete enum, which
     * the generic factory cannot declare). The container builds the generic
     * factory — including per-execution resolution of #[ExecutionScoped]
     * implementations — and wraps it in this class for #[InjectAsFactory].
     *
     * @param list<array{module: string, class: string}> $implementations
     */
    private static function writeFactoryClass(string $root, string $baseInterface, string $factoryInterface, array $implementations): ?string
    {
        $implementations = self::inClassOrder($implementations);
        try {
            $baseRef = new ReflectionClass($baseInterface);
            $factoryRef = new ReflectionClass($factoryInterface);
        } catch (\Throwable $e) {
            return null;
        }
        $factoryShortName = preg_replace('/Interface$/', '', $baseRef->getShortName());
        if ($factoryShortName === $baseRef->getShortName()) {
            $factoryShortName = $baseRef->getShortName();
        }
        $factoryShortName .= 'Factory';
        $outPath = $root . '/' . CanonicalRegistryPaths::REGISTRY_CONTRACTS . '/' . $factoryShortName . '.php';

        /** @var array<string, string> $imports */
        $imports = [];
        $usedShortNames = self::namesTakenBy($factoryShortName);
        $innerTypeHint = self::addImport(ContractFactoryInterface::class, $imports, $usedShortNames);
        $factoryInterfaceTypeHint = self::addImport($factoryInterface, $imports, $usedShortNames);
        $baseInterfaceTypeHint = self::addImport($baseInterface, $imports, $usedShortNames);
        $enumTypeHint = self::resolveFactoryEnumTypeHint($factoryRef, $imports, $usedShortNames);
        if ($enumTypeHint === null) {
            return null;
        }

        $seenKeys = [];
        foreach ($implementations as $impl) {
            $implClass = $impl['class'];
            $lookupKey = self::resolveFactoryKeyValue($implClass, $baseInterface);
            if ($lookupKey === null) {
                // Factory contracts require an enum-backed factoryKey on every
                // implementation (enforced by GraphBuilder); skip defensively.
                continue;
            }
            // A reused factoryKey would silently hide one implementation.
            // Fail at generation time instead of surfacing as missing behavior.
            if (isset($seenKeys[$lookupKey])) {
                throw new \RuntimeException(sprintf(
                    "Factory contract %s has duplicate factoryKey '%s' on %s and %s.",
                    $baseInterface,
                    $lookupKey,
                    $implClass,
                    $seenKeys[$lookupKey],
                ));
            }
            $seenKeys[$lookupKey] = $implClass;
        }

        $useBlock = self::formatUseBlock($imports);

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace App\Registry\Contracts;

{$useBlock}

/**
 * AUTO-GENERATED. Regenerate via: bin/semitexa registry:sync:contracts
 * Factory for {$baseInterface}. Implements {$factoryInterface}. Enum-keyed and closed-world.
 * The container constructs it around its generic factory for #[InjectAsFactory].
 */
final class {$factoryShortName} implements {$factoryInterfaceTypeHint}
{
    public function __construct(
        private {$innerTypeHint} \$factory,
    ) {
    }

    public function getDefault(): {$baseInterfaceTypeHint}
    {
        \$implementation = \$this->factory->getDefault();
        \assert(\$implementation instanceof {$baseInterfaceTypeHint});

        return \$implementation;
    }

    public function get({$enumTypeHint} \$key): {$baseInterfaceTypeHint}
    {
        \$implementation = \$this->factory->get(\$key);
        \assert(\$implementation instanceof {$baseInterfaceTypeHint});

        return \$implementation;
    }

    /** @return list<{$enumTypeHint}> */
    public function keys(): array
    {
        /** @var list<{$enumTypeHint}> */
        return \$this->factory->keys();
    }
}

PHP;

        file_put_contents($outPath, $content);
        return CanonicalRegistryPaths::REGISTRY_CONTRACTS_NAMESPACE . '\\' . $factoryShortName;
    }

    /**
     * Read the enum-backed factoryKey value from an implementation's
     * #[SatisfiesServiceContract(of: <baseInterface>, factoryKey: <enum>)] attribute.
     *
     * This is the key consumers pass to the factory's get(), so the generated
     * `$byKey` map is keyed by it. Returns null when the implementation declares
     * no factoryKey for this contract.
     */
    private static function resolveFactoryKeyValue(string $implClass, string $baseInterface): int|string|null
    {
        try {
            $ref = new ReflectionClass($implClass);
        } catch (\Throwable) {
            return null;
        }

        foreach ($ref->getAttributes(SatisfiesServiceContract::class) as $attrRef) {
            $attr = $attrRef->newInstance();
            // Normalize like ServiceContractRegistry does — a leading backslash
            // on `of` must not silently drop the implementation from the factory.
            if (ltrim($attr->of, '\\') === $baseInterface && $attr->factoryKey instanceof \BackedEnum) {
                return $attr->factoryKey->value;
            }
        }

        return null;
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
     * The implementations by class name, never in discovery order: that order differs
     * between machines and filesystems, and the files followed it, so a fresh clone
     * came up with every committed resolver rewritten, its use lines, parameters and
     * aliases reordered (tk-registry-resolvers-reorder-on-every-boot). The active
     * implementation is named separately, so the order only ever decided the bytes.
     *
     * @param list<array{module: string, class: string}> $implementations
     * @return list<array{module: string, class: string}>
     */
    private static function inClassOrder(array $implementations): array
    {
        usort($implementations, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return $implementations;
    }

    /**
     * The names a generated file starts with: the class it declares. An import under
     * that name would not compile either.
     *
     * @return array<string, string> lower-cased name => the class holding it
     */
    private static function namesTakenBy(string $generatedShortName): array
    {
        return [strtolower($generatedShortName) => CanonicalRegistryPaths::REGISTRY_CONTRACTS_NAMESPACE . '\\' . $generatedShortName];
    }

    /**
     * @param array<string, string> $imports
     * @param-out array<string, string> $imports
     * @param array<string, string> $usedShortNames lower-cased name => the class holding it
     * @param-out array<string, string> $usedShortNames
     */
    private static function addImport(string $fqn, array &$imports, array &$usedShortNames): string
    {
        if (isset($imports[$fqn])) {
            return $imports[$fqn];
        }
        $short = (new ReflectionClass($fqn))->getShortName();
        $name = $short;
        // Names are reserved as PHP compares them: case-insensitively, the declared class
        // included. A name another class holds gets the first free Alias<n>, reserved in
        // turn. Counting the names in use gave two classes one alias once the class-name
        // order put three of them in a row, and FixedClock/fixedClock or Foo/FooALIAS1
        // were two names only to an array key: PHP refuses such a file (review, core#245).
        for ($n = 1; isset($usedShortNames[strtolower($name)]); $n++) {
            $name = $short . 'Alias' . $n;
        }
        $usedShortNames[strtolower($name)] = $fqn;
        $imports[$fqn] = $name;

        return $name;
    }

    /**
     * @param array<string, string> $imports
     */
    private static function formatUseBlock(array $imports): string
    {
        ksort($imports, SORT_STRING);
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
