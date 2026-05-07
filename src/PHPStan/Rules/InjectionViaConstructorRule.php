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

        if (count($node->params) === 0) {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $nativeReflection = $classReflection->getNativeReflection();
        foreach (self::CONTAINER_MANAGED_ATTRIBUTES as $attrClass) {
            if ($nativeReflection->getAttributes($attrClass) !== []) {
                return [
                    RuleErrorBuilder::message(
                        sprintf(
                            'Constructor injection is not the DI channel on container-managed %s. '
                            . 'Declare dependencies as protected properties with #[InjectAsReadonly], '
                            . '#[InjectAsMutable], or #[InjectAsFactory] (and #[Config] for scalar '
                            . 'configuration) instead of constructor parameters. '
                            . 'Constructors themselves are not banned — a parameterless __construct '
                            . 'for local initialization is still allowed on this class, and '
                            . 'constructors are unrestricted on non-container-managed types '
                            . '(DTOs, payloads, resources, value objects).',
                            $classReflection->getName(),
                        )
                    )->identifier('semitexa.injectionViaConstructor')->build(),
                ];
            }
        }

        return [];
    }
}
