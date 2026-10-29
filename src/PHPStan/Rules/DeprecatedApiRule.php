<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.deprecatedApi
 *
 * The deprecation convention for the public API (docs: get-started/stability).
 * A part of the `@api` surface — a class marked `@api`, its public methods and
 * constants, or a member marked `@api` itself — that is deprecated says when
 * and what to use instead:
 *
 *     @deprecated 2026.11.02 Use Clock::now() instead.
 *
 * The date (`YYYY.MM.DD`, or a release version `YYYY.MM.DD.HHMM`) starts the
 * 90 days it must keep working; it cannot lie in the future. A native
 * `#[\Deprecated]` next to it gives the same `since`. Code outside `@api` may
 * deprecate however it likes: nothing is promised for it.
 *
 * @implements Rule<InClassNode>
 */
final class DeprecatedApiRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: a deprecated @api part may be removed 90 days after it was deprecated, and users count those days from the date written here; without a date nothing can be counted, and without a replacement nobody can move off it. Policy since 2026-10-29 (the stability contract); no incident on record.';

    private const SINCE = '/^\d{4}\.\d{2}\.\d{2}(\.\d{4})?$/';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getOriginalNode();
        $className = $node->getClassReflection()->getName();
        $classIsApi = self::hasTag($class->getDocComment(), 'api');

        $errors = [];
        if ($classIsApi) {
            $errors = [...$errors, ...$this->check($class, $className, $class->getDocComment(), $class->attrGroups)];
        }
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof ClassMethod && !$stmt instanceof ClassConst) {
                continue;
            }
            $doc = $stmt->getDocComment();
            if (!self::hasTag($doc, 'api') && !($classIsApi && $stmt->isPublic())) {
                continue;
            }
            $name = $stmt instanceof ClassMethod
                ? $className . '::' . $stmt->name->toString() . '()'
                : $className . '::' . implode(', ', array_map(static fn ($c): string => $c->name->toString(), $stmt->consts));
            $errors = [...$errors, ...$this->check($stmt, $name, $doc, $stmt->attrGroups)];
        }

        return $errors;
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     * @return list<IdentifierRuleError>
     */
    private function check(ClassLike|ClassMethod|ClassConst $node, string $name, ?Doc $doc, array $attrGroups): array
    {
        $tag = self::tag($doc, 'deprecated');
        $attributeSince = self::attributeSince($attrGroups);
        if ($tag === null && $attributeSince === null) {
            return [];
        }
        if ($tag === null) {
            return [$this->error($node, sprintf('%s is @api and #[\Deprecated], with no @deprecated tag. Write `@deprecated <YYYY.MM.DD> <what to use instead>` in its docblock.', $name))];
        }

        $since = strtok($tag, " \t\n") ?: '';
        $instead = trim(substr($tag, strlen($since)));
        $errors = [];
        if (preg_match(self::SINCE, $since) !== 1) {
            $errors[] = $this->error($node, sprintf('%s is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> %s`.', $name, $tag !== '' ? $tag : '<what to use instead>'));
        } elseif (substr($since, 0, 10) > gmdate('Y.m.d')) {
            $errors[] = $this->error($node, sprintf('%s is @deprecated from %s, a date still to come. Write the date it was deprecated: the 90 days count from it.', $name, $since));
        } elseif ($attributeSince !== '' && $attributeSince !== null && $attributeSince !== $since) {
            $errors[] = $this->error($node, sprintf('%s says @deprecated %s but #[\Deprecated(since: \'%s\')]. Give both the same date.', $name, $since, $attributeSince));
        }
        if (preg_match(self::SINCE, $since) === 1 && $instead === '') {
            $errors[] = $this->error($node, sprintf('%s is @api and @deprecated without saying what to use instead. Name it after the date: `@deprecated %s Use ... instead.`', $name, $since));
        }

        return $errors;
    }

    private function error(Node $node, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->line($node->getStartLine())
            ->tip(self::RATIONALE)->identifier('semitexa.deprecatedApi')
            ->build();
    }

    private static function hasTag(?Doc $doc, string $tag): bool
    {
        return $doc !== null && preg_match('/^\s*\*?\s*@' . $tag . '\b/m', $doc->getText()) === 1;
    }

    /** The text after @<tag>, continuation lines included up to the next tag; null without the tag. */
    private static function tag(?Doc $doc, string $tag): ?string
    {
        if ($doc === null || preg_match('/@' . $tag . '\b(.*?)(?=^\s*\*\s*@|\*\/)/ms', $doc->getText(), $m) !== 1) {
            return null;
        }

        return trim((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $m[1]));
    }

    /**
     * The `since` of a native #[\Deprecated]: '' when it gives none, null without the attribute.
     *
     * @param array<AttributeGroup> $attrGroups
     */
    private static function attributeSince(array $attrGroups): ?string
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (ltrim($attr->name->toString(), '\\') !== 'Deprecated') {
                    continue;
                }
                foreach ($attr->args as $i => $arg) {
                    if (($arg->name?->toString() ?? ($i === 1 ? 'since' : '')) === 'since' && $arg->value instanceof String_) {
                        return $arg->value->value;
                    }
                }

                return '';
            }
        }

        return null;
    }
}
