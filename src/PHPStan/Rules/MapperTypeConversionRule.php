<?php

declare(strict_types=1);

namespace Semitexa\Core\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * semitexa.mapperTypeConversion
 *
 * Flags a mapper that converts a column type the hydrator already converts.
 *
 * The ORM owns COLUMN-TYPE conversion: `TypeCaster` turns a BINARY(16) column
 * into a canonical UUID string on the way out and back into 16 bytes on the way
 * in, for every read and every write, with no mapper involved. A mapper owns
 * the storage-shape decisions the column type cannot express — the JSON string
 * that becomes an array, an enum spelled across two columns. That division was
 * never written down anywhere a mapper author would read it, so two mappers
 * independently re-did the hydrator's work on a uuid.
 *
 * Doing it twice is not a harmless duplicate. On the read path the mapper is
 * handed the 36-character string the hydrator produced and calls
 * `Uuid7::fromBytes()` on it, which throws «Expected 16 bytes, got 36» — and
 * because the write engine maps every persisted row back to its domain model,
 * one such mapper took down every scheduler job on the first history row it
 * wrote, whichever job it was. On the write path it happens to work, which is
 * worse: the bug sits in the file looking like a deliberate pair.
 *
 * Neither was caught by anything, in a sweep that rewrote nineteen mappers. The
 * count was two only because most swept records carry no BINARY(16) uuid, so
 * the rule is here for the next one that does rather than for the two that are
 * already fixed.
 *
 * Raw queries are a different matter and deliberately out of scope: a value
 * bound into a hand-written WHERE never passes through hydration, so converting
 * it there is correct. Those call sites live in repositories, which this rule
 * does not look at.
 *
 * @implements Rule<StaticCall>
 */
final class MapperTypeConversionRule implements Rule
{
    private const MAPPER_CONTRACT = 'Semitexa\\Orm\\Domain\\Contract\\ResourceModelMapperInterface';

    /** Converters the hydrator already applies, by class and method. */
    private const HYDRATOR_OWNED = [
        'Semitexa\\Orm\\Application\\Service\\Uuid7' => ['tobytes', 'frombytes'],
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return [];
        }

        // Resolved against the file scope so an aliased import still matches.
        $called = ltrim($scope->resolveName($node->class), '\\');
        $method = strtolower($node->name->name);

        $owned = self::HYDRATOR_OWNED[$called] ?? null;

        if ($owned === null || !in_array($method, $owned, true)) {
            return [];
        }

        $class = $scope->getClassReflection();

        if ($class === null || !$class->implementsInterface(self::MAPPER_CONTRACT)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s is a mapper and calls %s::%s(), which the ORM has already done. %s '
                . 'Pass the field straight through in both directions. The hydrator owns '
                . 'column-type conversion; a mapper owns the storage-shape decisions the column '
                . 'type cannot express, such as a JSON string that becomes an array — keep that '
                . 'half. Binding a value into a raw WHERE is not this: that belongs in the '
                . 'repository, where nothing hydrates it.',
                $class->getName(),
                $called,
                $node->name->name,
                self::whatGoesWrong($method),
            ))->identifier('semitexa.mapperTypeConversion')->build(),
        ];
    }

    /**
     * The consequence, which is not the same in both directions.
     *
     * Saying «Expected 16 bytes, got 36» for `toBytes()` would send the reader
     * looking for an exception that call does not raise: handed the canonical
     * string, it returns 16 bytes quite happily. The damage is one step later
     * and quieter, which is the harder half to find and so the half worth
     * describing accurately.
     */
    private static function whatGoesWrong(string $method): string
    {
        if ($method === 'frombytes') {
            return 'TypeCaster has already converted that BINARY(16) column to a canonical uuid '
                . 'string, so the mapper is handed 36 characters and converting them again throws '
                . '«Expected 16 bytes, got 36» — and the write engine maps every persisted row '
                . 'back to its domain model, so one such mapper fails every write of that table, '
                . 'not only its reads.';
        }

        return 'TypeCaster converts that column back to 16 bytes on the way to the database, so '
            . 'the mapper hands it raw bytes where it expects the canonical string it handed out '
            . '— a second conversion of an already-converted value, which is either a rejected '
            . 'write or a corrupted identifier depending on the column.';
    }
}
