<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Filter;

/**
 * Phase 6k: one resolved `(field, operator, value)` tuple from a
 * parsed `?filter=` request. Pure value object — the parser produces
 * a list of these and the apply step iterates them with AND
 * semantics.
 *
 * Value shape depends on the operator:
 *
 *   - `eq`       — single string scalar.
 *   - `in`       — list of strings, non-empty (validated by the parser).
 *   - `contains` — single string scalar.
 *
 * The match implementation lives here so callers do not have to
 * branch on the operator. The extractor receives `(item, field)` and
 * returns the comparable scalar (catalog rows: array key lookup;
 * future Resource DTOs: property read).
 */
final readonly class FilterTerm
{
    /**
     * @param string|list<string> $value
     */
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public string|array $value,
    ) {
    }

    /**
     * Test a single item against this term.
     *
     * @template T
     * @param T                          $item
     * @param callable(T, string):mixed  $extractor
     */
    public function matches(mixed $item, callable $extractor): bool
    {
        $raw = $extractor($item, $this->field);
        $haystack = $this->stringify($raw);

        return match ($this->operator) {
            FilterOperator::Eq       => is_string($this->value) && $haystack === $this->value,
            FilterOperator::In       => is_array($this->value) && in_array($haystack, $this->value, true),
            FilterOperator::Contains => is_string($this->value)
                && $this->value !== ''
                && stripos($haystack, $this->value) !== false,
        };
    }

    /**
     * Coerce an extracted value to the canonical string form used for
     * comparison. Mirrors the rule documented in the design doc:
     *
     *   - strings round-trip as-is;
     *   - integers / floats emit their PHP string form;
     *   - booleans emit `'true'` / `'false'`;
     *   - `null` emits an empty string (a string-eq filter against
     *     `null` therefore only matches `?filter=field:eq:`, which
     *     the parser already rejects);
     *   - other types emit an empty string (defensive — the
     *     allowlist gates which fields the extractor sees).
     */
    private function stringify(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return (string) $raw;
        }
        if (is_bool($raw)) {
            return $raw ? 'true' : 'false';
        }
        return '';
    }
}
