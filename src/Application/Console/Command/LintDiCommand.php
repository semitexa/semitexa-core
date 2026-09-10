<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
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
 * Verify DI rules on container-managed classes:
 * - no constructor-based injection (no __construct parameters — constructors
 *   themselves are allowed, just not as a DI channel);
 * - no static container access;
 * - injected properties are protected;
 * - #[Config] on scalars only, #[InjectAs*] on class types only.
 */
#[AsCommand(name: 'lint:di', description: 'Verify DI injection rules on all container-managed classes')]
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

            // Check: no constructor-based injection on container-managed classes.
            // A __construct with parameters is the unambiguous signal that the
            // constructor is being used as a DI channel, which One Way forbids.
            $ctor = $ref->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfParameters() > 0) {
                $errors[] = "{$class}: __construct has parameters. Container-managed classes receive dependencies through #[InjectAsReadonly] / #[InjectAsMutable] / #[InjectAsFactory] / #[Config] properties, not constructor arguments. An empty parameterless __construct is still allowed; initialization belongs in Semitexa\\Core\\Contract\\InitializesAfterInjectionInterface::initialize().";
            }

            // Check: a parameterless constructor that DOES something. The
            // container builds these with newInstanceWithoutConstructor(), so the
            // body never runs — it is code that reads as if it does. Reflection
            // cannot show a body, so this reads the source between the braces;
            // the phpstan rule (semitexa.inertConstructorBody) is the precise
            // one, and this is the check a developer gets without running it.
            if ($ctor !== null && $ctor->getNumberOfParameters() === 0 && self::constructorHasBody($ctor)) {
                $errors[] = "{$class}: the body of __construct() never runs — the container builds container-managed classes with newInstanceWithoutConstructor(). Move the work into initialize() and implement Semitexa\\Core\\Contract\\InitializesAfterInjectionInterface, which is called once every injected property is populated.";
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
     * True when a constructor has anything between its braces but comments.
     *
     * Reflection exposes no body, so the source is read back. A file it cannot
     * read, or a shape it cannot parse, answers FALSE: this check exists to name
     * a specific mistake, and guessing would turn it into noise on generated or
     * evaluated code where the answer is unknown.
     */
    private static function constructorHasBody(\ReflectionMethod $ctor): bool
    {
        $file = $ctor->getFileName();
        $start = $ctor->getStartLine();
        $end = $ctor->getEndLine();

        if ($file === false || $start === false || $end === false || !is_readable($file)) {
            return false;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        $source = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
        $open = strpos($source, '{');
        $close = strrpos($source, '}');
        if ($open === false || $close === false || $close <= $open) {
            return false;
        }

        $body = substr($source, $open + 1, $close - $open - 1);
        // Tokenize rather than strip with regexes: a brace or a semicolon inside
        // a string literal would fool the naive version into either answer.
        foreach (@token_get_all('<?php ' . $body) ?: [] as $token) {
            if (is_array($token)) {
                if (!in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    return true;
                }
                continue;
            }

            if (trim($token) !== '') {
                return true;
            }
        }

        return false;
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
