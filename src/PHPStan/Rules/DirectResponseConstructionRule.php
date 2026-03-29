<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.directResponseConstruction
 *
 * Flags `new Response(` or `Response::` static calls outside
 * semitexa-core/src/Http/ and semitexa-core/src/Pipeline/.
 *
 * @implements Rule<Node>
 */
final class DirectResponseConstructionRule implements Rule
{
    private const RESPONSE_CLASS = 'Semitexa\\Core\\Response';

    /** Directories where Response construction is allowed */
    private const ALLOWED_PATHS = [
        'semitexa-core/src/Http/',
        'semitexa-core/src/Pipeline/',
        'semitexa-core/src/Application.php',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $isResponseNew = $node instanceof New_
            && $node->class instanceof Node\Name
            && $this->isResponseClass($node->class);

        $isResponseStatic = $node instanceof StaticCall
            && $node->class instanceof Node\Name
            && $this->isResponseClass($node->class);

        if (!$isResponseNew && !$isResponseStatic) {
            return [];
        }

        $file = $scope->getFile();
        foreach (self::ALLOWED_PATHS as $allowed) {
            if (str_contains($file, $allowed)) {
                return [];
            }
        }

        $action = $isResponseNew ? 'new Response(' : 'Response:: static call';
        return [
            RuleErrorBuilder::message(
                sprintf(
                    '%s is forbidden in application code. '
                    . 'Handlers must return ResourceInterface DTOs. '
                    . 'Only framework kernel code (Http/, Pipeline/) may construct Response objects.',
                    $action,
                )
            )->identifier('semitexa.directResponseConstruction')->build(),
        ];
    }

    private function isResponseClass(Node\Name $name): bool
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return $resolved->toString() === self::RESPONSE_CLASS;
        }

        return $name->toString() === self::RESPONSE_CLASS;
    }
}
