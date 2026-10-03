<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.eventListenerMissingExecution
 *
 * Flags #[AsEventListener] without explicit `execution:` parameter.
 *
 * @implements Rule<Class_>
 */
final class EventListenerMissingExecutionRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: Sync runs before the response and Async/Queued after it; a default would make that choice for the author, and the caller could not tell which one happened. Policy since 2026-03-28; no incident on record.';

    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();
                if ($name !== 'Semitexa\\Core\\Attributes\\AsEventListener'
                    && $name !== 'AsEventListener') {
                    continue;
                }

                // Check if 'execution' named arg is present
                $hasExecution = false;
                foreach ($attr->args as $arg) {
                    if ($arg->name !== null && $arg->name->name === 'execution') {
                        $hasExecution = true;
                        break;
                    }
                }

                // Check positional args: execution is the 2nd parameter
                if (!$hasExecution && count($attr->args) >= 2) {
                    $secondArg = $attr->args[1];
                    if ($secondArg->name === null) {
                        $hasExecution = true; // Positional 2nd arg = execution
                    }
                }

                if (!$hasExecution) {
                    $className = $node->name?->name ?? 'unknown';
                    return [
                        RuleErrorBuilder::message(
                            sprintf(
                                '#[AsEventListener] on %s must specify execution: explicitly. '
                                . 'Choose EventExecution::Sync, EventExecution::Async, or EventExecution::Queued. '
                                . 'No default is provided.',
                                $className,
                            )
                        )->tip(self::RATIONALE)->identifier('semitexa.eventListenerMissingExecution')->build(),
                    ];
                }
            }
        }

        return [];
    }
}
