<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify DI rules: no constructor params, no static container access, protected visibility,
 * #[Config] on scalars only, #[InjectAs*] on class types only.
 */
#[AsCommand(name: 'semitexa:lint:di', description: 'Verify DI injection rules on all container-managed classes')]
final class LintDiCommand extends BaseCommand
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
        private readonly AttributeDiscovery $attributeDiscovery,
    ) {
        parent::__construct();
    }

    private const CONTAINER_MANAGED_ATTRIBUTES = [
        AsService::class,
        'Semitexa\\Orm\\Attribute\\AsRepository',
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
        SatisfiesServiceContract::class,
        SatisfiesRepositoryContract::class,
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lint: Dependency Injection');

        $errors = [];
        $classesChecked = 0;

        // Collect all container-managed classes
        $classes = [];
        foreach (self::CONTAINER_MANAGED_ATTRIBUTES as $attrClass) {
            foreach ($this->classDiscovery->findClassesWithAttribute($attrClass) as $class) {
                $classes[$class] = true;
            }
        }
        foreach ($this->attributeDiscovery->getDiscoveredPayloadHandlerClassNames() as $class) {
            $classes[$class] = true;
        }

        foreach (array_keys($classes) as $class) {
            $classesChecked++;
            try {
                $ref = new \ReflectionClass($class);
            } catch (\Throwable $e) {
                $errors[] = "{$class}: Cannot reflect — {$e->getMessage()}";
                continue;
            }

            // Check: no constructor parameters
            $ctor = $ref->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
                $errors[] = "{$class}: Constructor has parameters. Container-managed objects must not have constructor parameters.";
            }

            // Check all properties
            foreach ($ref->getProperties() as $prop) {
                $hasInject = $prop->getAttributes(InjectAsReadonly::class) !== []
                    || $prop->getAttributes(InjectAsMutable::class) !== []
                    || $prop->getAttributes(InjectAsFactory::class) !== [];
                $hasConfig = $prop->getAttributes(Config::class) !== [];

                if (!$hasInject && !$hasConfig) {
                    continue;
                }

                // Visibility check
                if (!$prop->isProtected()) {
                    $vis = $prop->isPrivate() ? 'private' : 'public';
                    $errors[] = "{$class}::\${$prop->getName()}: Injected property is {$vis}, must be protected.";
                }

                // Trait check
                $declaringTrait = $this->findDeclaringTraitForProperty($ref, $prop->getName());
                if ($declaringTrait !== null) {
                    $errors[] = "{$class}::\${$prop->getName()}: Injection attribute inside trait {$declaringTrait} is forbidden.";
                }

                $type = $prop->getType();

                // #[Config] type check
                if ($hasConfig) {
                    if ($type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === 'array') {
                            $errors[] = "{$class}::\${$prop->getName()}: #[Config] on array type is forbidden.";
                        } elseif (!$type->isBuiltin()) {
                            $typeRef = null;
                            try {
                                $typeRef = new \ReflectionClass($typeName);
                            } catch (\ReflectionException $e) {
                                $errors[] = "{$class}::\${$prop->getName()}: #[Config] type {$typeName} could not be reflected.";
                            }

                            if ($typeRef !== null && (!$typeRef->isEnum() || !$typeRef->implementsInterface(\BackedEnum::class))) {
                                $errors[] = "{$class}::\${$prop->getName()}: #[Config] on class type {$typeName}. Must be scalar or backed enum.";
                            }
                        } elseif (!in_array($typeName, ['int', 'float', 'string', 'bool'], true)) {
                            $errors[] = "{$class}::\${$prop->getName()}: #[Config] on unsupported type {$typeName}.";
                        }
                    }
                }

                // #[InjectAs*] type check
                if ($hasInject) {
                    if ($type instanceof \ReflectionNamedType) {
                        if ($type->isBuiltin()) {
                            $nullable = $type->allowsNull() ? ' Nullable scalar injection is also forbidden.' : '';
                            $errors[] = "{$class}::\${$prop->getName()}: #[InjectAs*] on scalar type {$type->getName()}. Use #[Config] instead.{$nullable}";
                        } elseif ($type->allowsNull()) {
                            $errors[] = "{$class}::\${$prop->getName()}: Nullable injected properties are forbidden on container-managed framework objects.";
                        }
                    }
                }
            }
        }

        if ($errors === []) {
            $io->success(sprintf('All %d container-managed classes pass DI lint.', $classesChecked));
            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        $io->error(sprintf('%d error(s) found in %d classes.', count($errors), $classesChecked));
        return self::FAILURE;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function findDeclaringTraitForProperty(\ReflectionClass $class, string $propertyName): ?string
    {
        foreach ($class->getTraits() as $trait) {
            if ($trait->hasProperty($propertyName)) {
                return $trait->getName();
            }

            $nested = $this->findDeclaringTraitForProperty($trait, $propertyName);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
