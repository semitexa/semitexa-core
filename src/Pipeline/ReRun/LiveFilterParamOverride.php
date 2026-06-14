<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline\ReRun;

use Semitexa\Core\Attribute\LiveFilterParam;

/**
 * Track R · Intended Grid Model · Phase 2 (C3) — the FILTER-ONLY param override,
 * and the structural anti-poisoning boundary that makes it safe.
 *
 * A view-change COMMAND (page / limit / sort / filter change) arrives over the
 * already-open stream and must merge its new params into the held-open re-run's
 * cached DTO so the next frame is re-queried under the new view. The danger is
 * obvious: the SAME merge that lets a command change `page` could, if unbounded,
 * let it change `sessionId` (or any identity-bearing field) and re-point the
 * stream at another subject's data. This class makes that impossible BY
 * CONSTRUCTION, not by convention.
 *
 * THE INVARIANT (structural, R2 anti-poisoning class): a command param may
 * override a cached-DTO field IF AND ONLY IF that field carries
 * {@see LiveFilterParam}. The marker is the override ALLOW-LIST. A param targeting
 * any field that is NOT marked — `sessionId`, `httpRequest`, or any future
 * identity / tenant field — is structurally IGNORED: it never reaches a setter or
 * a property. Because identity fields are not (and must never be) marked
 * `#[LiveFilterParam]`, the override mechanism cannot touch them; it has no code
 * path that writes a non-marked property. Identity therefore still resolves only
 * from the live session in {@see \Semitexa\Core\Pipeline\RouteExecutor::reExecute()}
 * (the R2 invariant intact), exactly as it did before a view-change command
 * existed.
 *
 * The marker lives in semitexa-core and references no authorization type, so this
 * class introduces no upward dependency (`semitexa-core → no semitexa-authorization`).
 *
 * Domain validation is intentionally NOT performed here — it stays where it
 * already lives (the DTO's own criteria factory, e.g. allow-list / clamp / sort
 * whitelist), which runs downstream when the re-run resolves the frame. This class
 * only enforces the allow-list and coerces the raw command value to the marked
 * field's declared scalar type.
 */
final class LiveFilterParamOverride
{
    /**
     * Apply the command's params onto $dto IN PLACE, writing ONLY fields that
     * carry {@see LiveFilterParam}. Mutating in place (rather than cloning) is
     * deliberate: the override persists onto the worker-local cached DTO so the
     * LATEST view wins across BOTH a subsequent view-change re-run AND a later
     * mutation-driven re-run (the intended-model "one stream, latest view"
     * semantics). Only filter-shaped fields are mutated; the cached DTO's identity
     * fields are never written, so it remains the non-identity "request shape"
     * object R2 requires.
     *
     * @param array<string, mixed> $params raw command params (key => value)
     * @return array{applied: list<string>, ignored: list<string>} the field names
     *         that WERE overridden (marked + present) and those that were IGNORED
     *         (present in the command but NOT a marked field — the anti-poisoning
     *         evidence)
     */
    public static function apply(object $dto, array $params): array
    {
        if ($params === []) {
            return ['applied' => [], 'ignored' => []];
        }

        $allowList = self::allowedProperties($dto);

        $applied = [];
        $ignored = [];
        foreach ($params as $name => $value) {
            $name = (string) $name;
            $property = $allowList[$name] ?? null;
            if ($property === null) {
                // Not a marked field — structurally un-overridable. This is the
                // boundary: identity / session / tenant fields land here and are
                // dropped, never written.
                $ignored[] = $name;
                continue;
            }

            try {
                $property->setValue($dto, self::coerce($property, $value));
                $applied[] = $name;
            } catch (\TypeError) {
                // A marked field whose declared type coerce() cannot shape
                // (array / object / union) fed a mismatched command value. The
                // command is client-controlled, so a shape mismatch must be an
                // IGNORED param — never an uncaught TypeError inside the
                // held-open re-run tick.
                $ignored[] = $name;
            }
        }

        return ['applied' => $applied, 'ignored' => $ignored];
    }

    /**
     * The override allow-list: every property carrying {@see LiveFilterParam},
     * walking the full class hierarchy (a DTO may declare filter fields on a
     * parent). Keyed by property name; the {@see \ReflectionProperty} is made
     * accessible once so the set in {@see self::apply()} is a direct write.
     *
     * @return array<string, \ReflectionProperty>
     */
    private static function allowedProperties(object $dto): array
    {
        $allowed = [];
        $class = new \ReflectionClass($dto);
        while ($class !== false) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $name = $property->getName();
                if (isset($allowed[$name])) {
                    continue; // a child declaration already won
                }
                if ($property->getAttributes(LiveFilterParam::class) === []) {
                    continue;
                }
                $property->setAccessible(true);
                $allowed[$name] = $property;
            }
            $class = $class->getParentClass();
        }

        return $allowed;
    }

    /**
     * Coerce a raw command value (commonly a string off the wire) to the marked
     * field's declared scalar type. Domain validation is NOT done here — only the
     * type shape — so a value the criteria factory would reject still reaches it
     * and is rejected there, producing the handler's normal error frame.
     */
    private static function coerce(\ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();
        if (!$type instanceof \ReflectionNamedType) {
            // Union / intersection / untyped — pass through unchanged.
            return $value;
        }

        $allowsNull = $type->allowsNull();
        if ($value === null) {
            return $allowsNull ? null : $value;
        }

        return match ($type->getName()) {
            'int' => self::coerceInt($value, $allowsNull),
            'float' => is_numeric($value) ? (float) $value : ($allowsNull ? null : 0.0),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'string' => self::coerceString($value, $allowsNull),
            default => $value,
        };
    }

    private static function coerceInt(mixed $value, bool $allowsNull): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value === '') {
            return $allowsNull ? null : 0;
        }

        return is_numeric($value) ? (int) $value : ($allowsNull ? null : 0);
    }

    private static function coerceString(mixed $value, bool $allowsNull): ?string
    {
        if (!is_scalar($value)) {
            return $allowsNull ? null : '';
        }
        $string = trim((string) $value);
        if ($string === '' && $allowsNull) {
            // Mirror the grid DTO's empty→null normalisation so clearing a filter
            // via a command resets it rather than searching for an empty string.
            return null;
        }

        return $string;
    }
}
