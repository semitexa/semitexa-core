<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Support;

/**
 * PHP source as the tests that read it need it.
 *
 * The runtime-safety tests forbid calls in a source file and must not be fooled
 * by a comment that names one: each of twelve carried its own copy of this.
 */
final class PhpSource
{
    /** The source with every comment and docblock removed, the code untouched. */
    public static function withoutComments(string $source): string
    {
        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
