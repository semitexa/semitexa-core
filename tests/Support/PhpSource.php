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
    /**
     * The source with every comment and docblock removed, the code untouched.
     *
     * An empty one is refused: a file that could not be read arrives here as
     * '' through a (string) cast, and '' contains none of the calls a test
     * forbids, so it passed as clean without ever being looked at.
     */
    public static function withoutComments(string $source): string
    {
        if (trim($source) === '') {
            throw new \RuntimeException('An empty source cannot be cleared: the file was unreadable or empty.');
        }
        $code = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }
}
