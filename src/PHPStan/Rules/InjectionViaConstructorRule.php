<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.injectionViaConstructor
 *
 * Enforces the Semitexa "One Way" DI policy on container-managed framework
 * objects (classes annotated with #[AsService], #[AsCommand], #[AsPayloadHandler],
 * #[AsEventListener], #[AsPipelineListener], #[AsServerLifecycleListener],
 * #[SatisfiesServiceContract], #[SatisfiesRepositoryContract], or #[AsRepository]).
 *
 * On those classes, dependencies must flow through *properties* annotated with
 * #[InjectAsReadonly] / #[InjectAsMutable] / #[InjectAsFactory] / #[Config].
 * Using a constructor signature as the injection channel is therefore not allowed.
 *
 * Important: this rule forbids constructor-based *injection*, not constructors.
 * A parameterless `__construct` on a container-managed class is allowed — the
 * container instantiates via `newInstanceWithoutConstructor()` by design, but a
 * no-arg constructor is untouched and fine for local initialization. Constructors
 * are likewise unrestricted on value objects, DTOs, payloads, resources, and any
 * other class that is not container-managed.
 *
 * The rule triggers only when a container-managed class declares `__construct`
 * with one or more parameters — the unambiguous signal that the constructor is
 * being used as a DI channel.
 *
 * @implements Rule<ClassMethod>
 */
final class InjectionViaConstructorRule implements Rule
{
    private const CONTAINER_MANAGED_ATTRIBUTES = [
        AsService::class,
        'Semitexa\\Orm\\Attribute\\AsRepository',
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
        AsServerLifecycleListener::class,
        AsCommand::class,
        SatisfiesServiceContract::class,
        SatisfiesRepositoryContract::class,
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name->name !== '__construct') {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        // A parameterless constructor is not injection, but the container never
        // calls it either: these objects are built with
        // newInstanceWithoutConstructor(). An EMPTY one is therefore harmless.
        // One with a body is code that looks like it runs and does not — the
        // silent kind of wrong, which is why it is reported rather than ignored.
        if (count($node->params) === 0) {
            // A comment on its own line parses as a Nop statement, so `stmts`
            // is not empty for a constructor that only explains why it is
            // empty. Those are the ones most likely to exist deliberately.
            $statements = array_filter(
                $node->stmts ?? [],
                static fn(Node\Stmt $statement): bool => !$statement instanceof Node\Stmt\Nop,
            );

            if ($statements === []) {
                return [];
            }

            if (!$this->isContainerManaged($classReflection)) {
                return [];
            }

            return [
                RuleErrorBuilder::message(
                    sprintf(
                        'The body of %s::__construct() never runs: the container builds '
                        . 'container-managed classes with newInstanceWithoutConstructor(), so a '
                        . 'constructor is skipped entirely. Move this work into '
                        . 'initialize() and implement '
                        . 'Semitexa\Core\Contract\InitializesAfterInjectionInterface, which the '
                        . 'container calls once every injected property is populated. An empty '
                        . '__construct() stays allowed, and constructors are unrestricted on '
                        . 'non-container-managed types (DTOs, payloads, resources, value objects).',
                        $classReflection->getName(),
                    )
                )->identifier('semitexa.inertConstructorBody')->build(),
            ];
        }

        if ($this->isContainerManaged($classReflection)) {
            return [
                    RuleErrorBuilder::message(
                        sprintf(
                            'Constructor injection is not the DI channel on container-managed %s. '
                            . 'Declare dependencies as protected properties with #[InjectAsReadonly], '
                            . '#[InjectAsMutable], or #[InjectAsFactory] (and #[Config] for scalar '
                            . 'configuration) instead of constructor parameters. '
                            . 'Constructors themselves are not banned — an EMPTY parameterless '
                            . '__construct is still allowed here (the container never calls it, so '
                            . 'a body would not run); initialization belongs in '
                            . 'Semitexa\Core\Contract\InitializesAfterInjectionInterface::initialize(). '
                            . 'Constructors are unrestricted on non-container-managed types '
                            . '(DTOs, payloads, resources, value objects).',
                            $classReflection->getName(),
                        )
                    )->identifier('semitexa.injectionViaConstructor')->build(),
            ];
        }

        return [];
    }

    private function isContainerManaged(ClassReflection $classReflection): bool
    {
        $nativeReflection = $classReflection->getNativeReflection();

        foreach (self::CONTAINER_MANAGED_ATTRIBUTES as $attrClass) {
            if ($nativeReflection->getAttributes($attrClass) !== []) {
                return true;
            }
        }

        return false;
    }
}
