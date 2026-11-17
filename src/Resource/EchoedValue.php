<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource;

/**
 * A piece of the client's own input, as a 400 repeats it back: at most
 * {@see self::MAX} characters, then "…". A rejected ?filter=, ?sort= or ?page=
 * used to come back whole in the message and the error context, however long
 * the client made it (tk-invalid-filter-echoes-the-raw-value). The cut counts
 * characters, not bytes, so it never splits a multi-byte character.
 *
 * @internal
 */
final class EchoedValue
{
    public const MAX = 64;

    public static function of(string $input): string
    {
        if (mb_strlen($input, 'UTF-8') <= self::MAX) {
            return $input;
        }

        return mb_substr($input, 0, self::MAX, 'UTF-8') . '…';
    }
}
