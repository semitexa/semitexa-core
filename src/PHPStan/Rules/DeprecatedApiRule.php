<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
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
 * The date (`YYYY.MM.DD`, or a release version `YYYY.MM.DD.HHMM`) is when it
 * was deprecated; the 90 days it must keep working count from the first
 * release that ships it, dated that day or later. It cannot lie in the future. A native
 * `#[\Deprecated]` next to it gives the same `since`. Code outside `@api` may
 * deprecate however it likes: nothing is promised for it.
 *
 * @implements Rule<InClassNode>
 */
final class DeprecatedApiRule implements Rule
{
    /** Printed with every report of this rule: why it exists, and what taught us. */
    private const RATIONALE = 'Why: a deprecated @api part may be removed 90 days after it was deprecated, counted from the release that shipped the date written here; without a date nothing can be counted, and without a replacement nobody can move off it. Policy since 2026-10-29 (the stability contract); no incident on record.';

    private const SINCE = '/^\d{4}\.\d{2}\.\d{2}(\.\d{4})?$/';

    /** @param ?string $now UTC `YYYY.MM.DD.HHMM` to compare against; the clock when null (a test fixes it) */
    public function __construct(private readonly ?string $now = null)
    {
    }

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
            $errors = [...$errors, ...$this->check($class, $className, $class->getDocComment(), $class->attrGroups, $scope)];
        }
        foreach ($class->stmts as $stmt) {
            // A promoted property is a property, marked @api on its own, whatever the constructor is.
            foreach ($stmt instanceof ClassMethod ? $stmt->params : [] as $param) {
                if ($param->isPromoted() && $param->var instanceof Variable && is_string($param->var->name) && self::hasTag($param->getDocComment(), 'api')) {
                    $errors = [...$errors, ...$this->check($param, $className . '::$' . $param->var->name, $param->getDocComment(), $param->attrGroups, $scope)];
                }
            }
            if (!$stmt instanceof ClassMethod && !$stmt instanceof ClassConst && !$stmt instanceof Property) {
                continue;
            }
            $doc = $stmt->getDocComment();
            // A property is in the surface only when marked itself: the convention names an
            // @api class's public methods and constants, not its state.
            $implied = $classIsApi && $stmt->isPublic() && !$stmt instanceof Property;
            if (!self::hasTag($doc, 'api') && !$implied) {
                continue;
            }
            $name = match (true) {
                $stmt instanceof ClassMethod => $className . '::' . $stmt->name->toString() . '()',
                $stmt instanceof ClassConst => $className . '::' . implode(', ', array_map(static fn ($c): string => $c->name->toString(), $stmt->consts)),
                default => $className . '::' . implode(', ', array_map(static fn ($p): string => '$' . $p->name->toString(), $stmt->props)),
            };
            $errors = [...$errors, ...$this->check($stmt, $name, $doc, $stmt->attrGroups, $scope)];
        }

        return $errors;
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     * @return list<IdentifierRuleError>
     */
    private function check(ClassLike|ClassMethod|ClassConst|Property|Param $node, string $name, ?Doc $doc, array $attrGroups, Scope $scope): array
    {
        $tag = self::tag($doc, 'deprecated');
        $attributeSince = self::attributeSince($attrGroups, $scope);
        if ($tag === null && $attributeSince === null) {
            return [];
        }
        if ($tag === null) {
            return [$this->error($node, sprintf('%s is @api and #[\Deprecated], with no @deprecated tag. Write `@deprecated <YYYY.MM.DD> <what to use instead>` in its docblock.', $name))];
        }

        $since = strtok($tag, " \t\n") ?: '';
        $instead = trim(substr($tag, strlen($since)));
        $errors = [];
        $dated = preg_match(self::SINCE, $since) === 1;
        if (!$dated) {
            $errors[] = $this->error($node, sprintf('%s is @api and @deprecated without a date. Start the tag with the date it was deprecated: `@deprecated <YYYY.MM.DD> %s`.', $name, $tag !== '' ? $tag : '<what to use instead>'));
        } elseif (!self::isRealMoment($since)) {
            $errors[] = $this->error($node, sprintf('%s is @deprecated from %s, which is no date on the calendar. Write the date it was deprecated.', $name, $since));
        } elseif ((strlen($since) === 10 ? $since . '.0000' : $since) > ($this->now ?? gmdate('Y.m.d.Hi'))) {
            $errors[] = $this->error($node, sprintf('%s is @deprecated from %s, a date still to come. Write the date it was deprecated.', $name, $since));
        } elseif ($attributeSince === '') {
            $errors[] = $this->error($node, sprintf('%s is @deprecated %s but its #[\Deprecated] gives no since. Write #[\Deprecated(since: \'%s\')].', $name, $since, $since));
        } elseif (is_string($attributeSince) && $attributeSince !== $since) {
            $errors[] = $this->error($node, sprintf('%s says @deprecated %s but #[\Deprecated(since: \'%s\')]. Give both the same date.', $name, $since, $attributeSince));
        }
        if ($dated && $instead === '') {
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

    /** 2026.02.30 or 2026.10.29.2575 has the shape of a date and is none. */
    private static function isRealMoment(string $since): bool
    {
        $format = strlen($since) === 10 ? '!Y.m.d' : '!Y.m.d.Hi';
        $parsed = \DateTimeImmutable::createFromFormat($format, $since, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format(substr($format, 1)) === $since;
    }

    /**
     * A tag opens a docblock line: `* @tag` or `/** @tag`, up to four spaces in.
     * Further in, it is an example inside the prose (a code block in Markdown),
     * as in this class's own docblock.
     */
    private const TAG_START = '^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@';

    private static function hasTag(?Doc $doc, string $tag): bool
    {
        return $doc !== null && preg_match('/' . self::TAG_START . $tag . '\b/m', $doc->getText()) === 1;
    }

    /** The text after @<tag>, continuation lines included up to the next tag; null without the tag. */
    private static function tag(?Doc $doc, string $tag): ?string
    {
        if ($doc === null || preg_match('/' . self::TAG_START . $tag . '\b(.*?)(?=^[ \t]*\*[ \t]{0,4}@|\*\/)/ms', $doc->getText(), $m) !== 1) {
            return null;
        }

        return trim((string) preg_replace('/\s*\n\s*\*\s*/', ' ', $m[1]));
    }

    /**
     * The `since` of a native #[\Deprecated]: null without the attribute, '' when it gives
     * none, false when it gives one that is not a single constant string, which is then
     * not compared.
     *
     * @param array<AttributeGroup> $attrGroups
     */
    private static function attributeSince(array $attrGroups, Scope $scope): string|false|null
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                // PHP class names are case-insensitive: #[\deprecated] is the same attribute.
                if (strcasecmp(ltrim($attr->name->toString(), '\\'), 'Deprecated') !== 0) {
                    continue;
                }
                foreach ($attr->args as $i => $arg) {
                    if (($arg->name?->toString() ?? ($i === 1 ? 'since' : '')) === 'since') {
                        return self::stringOf($arg->value, $scope);
                    }
                }

                return '';
            }
        }

        return null;
    }

    /** A string literal, or any constant expression PHPStan resolves to exactly one string. */
    private static function stringOf(Node\Expr $value, Scope $scope): string|false
    {
        if ($value instanceof String_) {
            return $value->value;
        }
        $strings = $scope->getType($value)->getConstantStrings();

        return count($strings) === 1 ? $strings[0]->getValue() : false;
    }
}
