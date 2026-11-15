<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Container\ExecutionContextAwareContainerInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify DI rules on container-managed classes:
 * - no constructor-based injection (no __construct parameters — constructors
 *   themselves are allowed, just not as a DI channel);
 * - no static container access;
 * - injected properties are protected;
 * - #[Config] on scalars only, #[InjectAs*] on class types only;
 * - #[InjectAsReadonly] never on an execution-scoped type, here and on commands,
 *   the edge objects PropertyInjector fills (tk-inject-from-context-attr).
 */
#[AsCommand(name: 'lint:di', description: 'Verify DI injection rules on all container-managed classes')]
final class LintDiCommand extends BaseCommand
{
    /** Why this check exists, and what taught us; ai:verify prints it when the lint fails. */
    public const RATIONALE = 'Why: the container builds container-managed classes with newInstanceWithoutConstructor() and injects properties afterwards, so a constructor parameter is never passed and a constructor body never runs. Learned 2026-09-10: a sweep across ten repositories found constructor bodies that had been silently skipped; the inert-body check dates from then.';

    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected AttributeDiscovery $attributeDiscovery;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

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

                $scoped = $this->readonlyInjectionOfExecutionScoped($class, $prop);
                if ($scoped !== null) {
                    $errors[] = $scoped;
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

        // Without the container's answer the execution-scope check cannot run:
        // that is a failure, not a quiet pass (independent review, core#238).
        if (!$this->container instanceof ExecutionContextAwareContainerInterface) {
            $errors[] = 'The container (' . $this->container::class . ') cannot say which types are execution-scoped, so #[InjectAsReadonly] was not checked against them.';
        }

        // Commands are not container-managed: PropertyInjector fills their
        // #[InjectAsReadonly] properties, and refuses an execution-scoped type
        // only when the command first runs. Only that rule applies to them; the
        // Symfony constructor they inherit takes a name.
        foreach ($this->classDiscovery->findClassesWithAttribute(AsCommand::class) as $command) {
            if (isset($classes[$command]) || !class_exists($command)) {
                continue;
            }
            $classesChecked++;
            foreach ((new \ReflectionClass($command))->getProperties() as $prop) {
                $scoped = $this->readonlyInjectionOfExecutionScoped($command, $prop);
                if ($scoped !== null) {
                    $errors[] = $scoped;
                }
            }
        }

        // A class discovery could not load was in none of the lists above, so
        // none of it was checked: that is not a pass (review, core#238).
        foreach ($this->classDiscovery->unloadableClasses() as $class => $reason) {
            $errors[] = "{$class}: could not be loaded, so lint:di cannot tell whether it is container-managed or a command: {$reason}";
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
     * The refusal PropertyInjector makes at runtime, asked of the same container,
     * so an interface resolves to the class it is bound to.
     */
    private function readonlyInjectionOfExecutionScoped(string $class, \ReflectionProperty $prop): ?string
    {
        $type = $prop->getType();
        if ($prop->getAttributes(InjectAsReadonly::class) === [] || !$type instanceof \ReflectionNamedType || $type->isBuiltin()
            || !$this->container instanceof ExecutionContextAwareContainerInterface || !$this->container->isExecutionScoped($type->getName())) {
            return null;
        }

        return "Cannot inject execution-scoped {$type->getName()} into {$class}::\${$prop->getName()} with #[InjectAsReadonly]. "
            . 'Use #[InjectAsMutable] on container-managed objects instead.';
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
