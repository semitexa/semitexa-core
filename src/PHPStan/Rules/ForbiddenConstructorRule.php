<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.forbiddenConstructor
 *
 * Flags any __construct with parameters on classes annotated with container-managed
 * framework object attributes (#[AsService], #[AsPayloadHandler], etc.).
 *
 * @implements Rule<ClassMethod>
 */
final class ForbiddenConstructorRule implements Rule
{
    private const CONTAINER_MANAGED_ATTRIBUTES = [
        'Semitexa\\Core\\Attributes\\AsService',
        'Semitexa\\Orm\\Attribute\\AsRepository',
        'Semitexa\\Core\\Attributes\\AsPayloadHandler',
        'Semitexa\\Core\\Attributes\\AsEventListener',
        'Semitexa\\Core\\Attributes\\AsPipelineListener',
        'Semitexa\\Core\\Attributes\\SatisfiesServiceContract',
        'Semitexa\\Core\\Attributes\\SatisfiesRepositoryContract',
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
                            'Container-managed framework object %s must not have constructor parameters. '
                            . 'Use #[InjectAsReadonly], #[InjectAsMutable], #[InjectAsFactory] for services '
                            . 'and #[Config] for scalar configuration.',
                            $classReflection->getName(),
                        )
                    )->identifier('semitexa.forbiddenConstructor')->build(),
                ];
            }
        }

        return [];
    }
}
