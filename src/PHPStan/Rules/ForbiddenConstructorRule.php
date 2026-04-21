<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @deprecated Kept as a backwards-compatible alias for older phpstan.neon files.
 *             Use {@see InjectionViaConstructorRule} directly.
 *
 * @implements Rule<ClassMethod>
 */
final class ForbiddenConstructorRule implements Rule
{
    private readonly InjectionViaConstructorRule $delegate;

    public function __construct()
    {
        $this->delegate = new InjectionViaConstructorRule();
    }

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return $this->delegate->processNode($node, $scope);
    }
}
