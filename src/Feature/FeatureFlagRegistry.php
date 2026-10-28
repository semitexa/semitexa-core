<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

use Semitexa\Core\Attribute\AsFeatureFlag;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Log\StaticLoggerBridge;

/**
 * Every #[AsFeatureFlag] in the installed modules. A declaration that does not
 * load, or a name that breaks the pattern, is skipped with a warning; two
 * declarations of one name keep the first and warn about the second.
 */
#[AsService]
final class FeatureFlagRegistry
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    /** @var array<string, FeatureFlag>|null */
    private ?array $flags = null;

    /** @param list<FeatureFlag> $flags */
    public static function of(array $flags): self
    {
        $registry = new self();
        $registry->flags = [];
        foreach ($flags as $flag) {
            $registry->flags[$flag->name] ??= $flag;
        }

        return $registry;
    }

    public function get(string $name): ?FeatureFlag
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<string, FeatureFlag> by name */
    public function all(): array
    {
        return $this->flags ??= $this->discover();
    }

    /** @return array<string, FeatureFlag> */
    private function discover(): array
    {
        $flags = [];
        foreach ($this->classDiscovery->findClassesWithAttribute(AsFeatureFlag::class) as $class) {
            if (!class_exists($class)) {
                // Every flag it declares would read as off, `default: true` too.
                StaticLoggerBridge::warning('feature', 'A class with feature flag declarations could not be loaded; its flags are ignored', ['class' => $class]);
                continue;
            }
            foreach ((new \ReflectionClass($class))->getAttributes(AsFeatureFlag::class) as $attribute) {
                try {
                    $declared = $attribute->newInstance();
                } catch (\Throwable $e) {
                    StaticLoggerBridge::warning('feature', 'A feature flag declaration could not be read', ['class' => $class, 'error' => $e->getMessage()]);
                    continue;
                }
                if (preg_match(FeatureFlag::NAME_PATTERN, $declared->name) !== 1) {
                    StaticLoggerBridge::warning('feature', 'A feature flag name breaks the pattern; it is ignored', ['flag' => $declared->name, 'class' => $class]);
                    continue;
                }
                if (isset($flags[$declared->name])) {
                    StaticLoggerBridge::warning('feature', 'A feature flag is declared twice; the first declaration is kept', [
                        'flag' => $declared->name,
                        'kept' => $flags[$declared->name]->declaredBy,
                        'ignored' => $class,
                    ]);
                    continue;
                }
                $flags[$declared->name] = new FeatureFlag(
                    $declared->name,
                    $declared->default,
                    $declared->description,
                    $declared->percent === null ? null : max(0, min(100, $declared->percent)),
                    $class,
                );
            }
        }
        ksort($flags);

        return $flags;
    }
}
