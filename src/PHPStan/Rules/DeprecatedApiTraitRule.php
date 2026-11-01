<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InTraitNode;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.deprecatedApi for traits: the same convention as DeprecatedApiRule
 * (an @api trait's public methods and constants, a member marked @api itself,
 * a promoted @api property of its constructor), which listens on InClassNode,
 * a node PHPStan never gives a trait.
 *
 * PHPStan analyses a trait in the context of each class that uses it, so it
 * reaches this rule once per user and, as with every error in a trait,
 * reports it "in context of class …" for each; the members are named after
 * the trait, so the message is the same. A trait no class uses is not
 * analysed by PHPStan at all, and so not checked here either.
 *
 * @implements Rule<InTraitNode>
 */
final class DeprecatedApiTraitRule implements Rule
{
    /** The class rule's: the same convention, so the same reason. */
    private const RATIONALE = DeprecatedApiRule::RATIONALE;

    private readonly DeprecatedApiRule $rule;

    /** @param ?string $now UTC `YYYY.MM.DD.HHMM` to compare against; the clock when null (a test fixes it) */
    public function __construct(?string $now = null)
    {
        $this->rule = new DeprecatedApiRule($now);
    }

    public function getNodeType(): string
    {
        return InTraitNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->rule->checkDeclaration($node->getOriginalNode(), $node->getTraitReflection()->getName(), $scope) as $error) {
            $errors[] = RuleErrorBuilder::message($error->getMessage())
                ->line($error instanceof LineRuleError ? $error->getLine() : $node->getStartLine())
                ->tip(self::RATIONALE)->identifier('semitexa.deprecatedApi')
                ->build();
        }

        return $errors;
    }
}
