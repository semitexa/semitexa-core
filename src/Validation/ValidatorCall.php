<?php

declare(strict_types=1);

namespace Semitexa\Core\Validation;

/**
 * Runs a caller-supplied validator on the error list.
 *
 * Every composing validation trait (collection, composite, conditional) hands
 * `$errors` to a callable by reference. PHPStan cannot know what a callable
 * leaves in a reference, so after the call the list reads as `mixed` and every
 * later write to it fails analysis. The validator's contract is to append
 * messages; this reads the list back through that shape, in one place.
 */
final class ValidatorCall
{
    /**
     * @param callable(array<string, list<string>>&, string, mixed): void $validator
     * @param array<string, list<string>> $errors
     * @param-out array<string, list<string>> $errors
     */
    public static function apply(callable $validator, array &$errors, string $path, mixed $value): void
    {
        $out = $errors;
        try {
            $validator($out, $path, $value);
        } finally {
            // Also on a throw: what the validator appended before it failed stays, as before.
            /** @var array<string, list<string>> $out */
            $errors = $out;
        }
    }
}
