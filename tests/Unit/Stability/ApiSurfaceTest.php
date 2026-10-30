<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Stability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What semitexa/core promises (docs: get-started/stability): the symbols
 * marked @api, and only those. The list is pinned so the promise grows only
 * on purpose: a mark added or dropped shows here, in the same review as the
 * code. A dropped one is a break, owed a 90-day deprecation first.
 */
final class ApiSurfaceTest extends TestCase
{
    #[Test]
    public function the_api_surface_is_the_one_promised(): void
    {
        self::assertSame([
            'Semitexa\Core\Attribute\AsCommand',
            'Semitexa\Core\Attribute\AsEvent',
            'Semitexa\Core\Attribute\AsEventListener',
            'Semitexa\Core\Attribute\AsPayloadHandler',
            'Semitexa\Core\Attribute\AsPipelineListener',
            'Semitexa\Core\Attribute\AsPublicPayload',
            'Semitexa\Core\Attribute\AsServerLifecycleListener',
            'Semitexa\Core\Attribute\AsService',
            'Semitexa\Core\Attribute\AsWorkerProcessSource',
            'Semitexa\Core\Attribute\Config',
            'Semitexa\Core\Attribute\ExecutionScoped',
            'Semitexa\Core\Attribute\InjectAsFactory',
            'Semitexa\Core\Attribute\InjectAsMutable',
            'Semitexa\Core\Attribute\InjectAsReadonly',
            'Semitexa\Core\Attribute\SatisfiesRepositoryContract',
            'Semitexa\Core\Attribute\SatisfiesServiceContract',
            'Semitexa\Core\Attribute\TransportType',
            'Semitexa\Core\Auth\AuthenticatableInterface',
            'Semitexa\Core\Console\BaseCommand',
            'Semitexa\Core\Contract\DoctorCheckInterface',
            'Semitexa\Core\Contract\ResourceInterface',
            'Semitexa\Core\Contract\TypedHandlerInterface',
            'Semitexa\Core\Contract\ValidatablePayloadInterface',
            'Semitexa\Core\Contract\WorkerProcessSourceInterface',
            'Semitexa\Core\Exception\DomainException',
            'Semitexa\Core\Http\Response\ResourceResponse::getContent()',
            'Semitexa\Core\Http\Response\ResourceResponse::getContext()',
            'Semitexa\Core\Http\Response\ResourceResponse::getHeaders()',
            'Semitexa\Core\Http\Response\ResourceResponse::getRedirectUrl()',
            'Semitexa\Core\Http\Response\ResourceResponse::getRenderHandle()',
            'Semitexa\Core\Http\Response\ResourceResponse::getStatusCode()',
            'Semitexa\Core\Http\Response\ResourceResponse::setContent()',
            'Semitexa\Core\Http\Response\ResourceResponse::setContext()',
            'Semitexa\Core\Http\Response\ResourceResponse::setHeader()',
            'Semitexa\Core\Http\Response\ResourceResponse::setRedirect()',
            'Semitexa\Core\Http\Response\ResourceResponse::setRenderHandle()',
            'Semitexa\Core\Http\Response\ResourceResponse::setStatusCode()',
            'Semitexa\Core\Pipeline\PipelineListenerInterface',
            'Semitexa\Core\Resource\JsonResourceResponse::withResource()',
            'Semitexa\Core\Resource\JsonResourceResponse::withResources()',
            'Semitexa\Core\Resource\ResourceObjectInterface',
            'Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface',
        ], self::marked(dirname(__DIR__, 3) . '/src'));
    }

    /**
     * The scan itself: a member marked on its own is part of the promise (as
     * semitexa.deprecatedApi reads it), an attribute may span lines, and a file
     * that cannot be read fails the scan instead of being read as empty.
     */
    #[Test]
    public function the_scan_sees_members_multiline_attributes_and_unread_files(): void
    {
        $dir = sys_get_temp_dir() . '/api-surface-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/Marked.php', <<<'PHP'
                <?php
                namespace Probe;

                /** @api */
                #[\Attribute(
                    \Attribute::TARGET_CLASS,
                )]
                final class Marked
                {
                    /** @api */
                    public const A = 1, B = [1, 2];

                    /** @api */
                    public function list(): void {}

                    public function notMarked(): void {}

                    /**
                     * @api
                     */
                    public function marked(): void
                    {
                        $x = new class { /** @api */ public function anonymous(): void {} };
                    }

                    /** @api */
                    public ?string $label = null, $note = [1, 2];
                }

                /**
                 *  @api
                 */
                final class Spaced
                {
                }

                /**
                 * Mark it so:
                 *
                 *     @api
                 */
                final class Prose
                {
                }

                final class Plain
                {
                    /** @api */
                    #[\Deprecated(
                        'Use marked().',
                    )]
                    public function old(): void {}
                }
                PHP);

            self::assertSame(['Probe\Marked', 'Probe\Marked::$label', 'Probe\Marked::$note', 'Probe\Marked::A', 'Probe\Marked::B', 'Probe\Marked::list()', 'Probe\Marked::marked()', 'Probe\Plain::old()', 'Probe\Spaced'], self::marked($dir));

            symlink($dir . '/nowhere.php', $dir . '/Broken.php');
            $failed = null;
            try {
                self::marked($dir);
            } catch (\RuntimeException $e) {
                $failed = $e->getMessage();
            }
            self::assertSame('cannot read ' . $dir . '/Broken.php', $failed);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Every class-like whose own docblock carries @api, and every member marked
     * on its own (`Class::method()`, `Class::CONST`, `Class::$property`).
     *
     * @return list<string>
     */
    private static function marked(string $root): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = @file_get_contents($file->getPathname());
            if (!is_string($source)) {
                throw new \RuntimeException('cannot read ' . $file->getPathname());
            }
            $found = [...$found, ...self::markedIn($source)];
        }
        sort($found);

        return $found;
    }

    /** @return list<string> */
    private static function markedIn(string $source): array
    {
        // TOKEN_PARSE: a member named list or default is an identifier, not T_LIST / T_DEFAULT.
        $tokens = array_values(\PhpToken::tokenize($source, TOKEN_PARSE));
        $count = count($tokens);
        $found = [];
        $namespace = '';
        $doc = null;
        $depth = 0;
        $class = null;
        $classDepth = -1;
        $parens = 0;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->is([T_WHITESPACE, T_COMMENT])) {
                continue;
            }
            if ($t->is(T_DOC_COMMENT)) {
                $doc = $t->text;
                continue;
            }
            if ($t->is(T_ATTRIBUTE)) {
                // Skip to its closing bracket, however many lines it spans; the docblock stays pending.
                for ($open = 1; $open > 0 && ++$i < $count;) {
                    $open += $tokens[$i]->text === '[' ? 1 : ($tokens[$i]->text === ']' ? -1 : 0);
                }
                continue;
            }
            if ($t->is(T_NAMESPACE)) {
                $namespace = '';
                for ($i++; $i < $count && !in_array($tokens[$i]->text, [';', '{'], true); $i++) {
                    if ($tokens[$i]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace = $tokens[$i]->text;
                    }
                }
                $doc = null;
                continue;
            }
            if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && !self::follows($tokens, $i, [T_DOUBLE_COLON, T_NEW])) {
                $name = self::next($tokens, $i, T_STRING);
                if ($name !== null && $class === null) {
                    $class = ltrim($namespace . '\\' . $name, '\\');
                    $classDepth = $depth;
                    if (self::isApi($doc)) {
                        $found[] = $class;
                    }
                }
                $doc = null;
                continue;
            }
            if ($class !== null && $depth === $classDepth + 1) {
                $member = match (true) {
                    $t->is(T_FUNCTION) => ($n = self::next($tokens, $i, T_STRING)) !== null ? $n . '()' : null,
                    $t->is(T_CONST) => self::constNames($tokens, $i),
                    // In a parameter list a promoted property is one name; a property
                    // declaration may name several, all under one docblock.
                    $t->is(T_VARIABLE) => $parens > 0 ? $t->text : self::propertyNames($tokens, $i),
                    $t->is(T_CASE) => self::next($tokens, $i, T_STRING),
                    default => false,
                };
                if ($member !== false) {
                    if ($member !== null && self::isApi($doc)) {
                        foreach ((array) $member as $one) {
                            $found[] = $class . '::' . $one;
                        }
                    }
                    $doc = null;
                    // Past the declaration's own name, so `$x` in a body is not a member.
                    if ($t->is(T_FUNCTION) || $t->is(T_CONST) || $t->is(T_CASE)) {
                        continue;
                    }
                }
            }
            if ($t->text === '(') {
                $parens++;
            } elseif ($t->text === ')') {
                $parens--;
            }
            if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                $doc = null;
            } elseif ($t->text === '}') {
                $depth--;
                $doc = null;
                if ($class !== null && $depth === $classDepth) {
                    $class = null;
                }
            } elseif ($t->text === ';') {
                $doc = null;
            }
        }

        return $found;
    }

    private static function isApi(?string $doc): bool
    {
        return $doc !== null && preg_match('/^[ \t]*(?:\*[ \t]{0,4}|\/\*\*[ \t]{0,4})@api\b/m', $doc) === 1;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @param list<int> $kinds
     */
    private static function follows(array $tokens, int $at, array $kinds): bool
    {
        for ($j = $at - 1; $j >= 0 && $tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]); $j--) {
        }

        return $j >= 0 && $tokens[$j]->is($kinds);
    }

    /** @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $at, int $kind): ?string
    {
        for ($j = $at + 1, $count = count($tokens); $j < $count; $j++) {
            if ($tokens[$j]->is($kind)) {
                return $tokens[$j]->text;
            }
            if (in_array($tokens[$j]->text, ['(', '{', ';', '='], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Every name of `public string $a, $b = 1;`, from the first: each variable at
     * the declaration's own level (a default value holds none).
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function propertyNames(array $tokens, int &$at): array
    {
        $names = [];
        $nesting = 0;
        for ($count = count($tokens); $at < $count && !($nesting === 0 && $tokens[$at]->text === ';'); $at++) {
            $text = $tokens[$at]->text;
            if (in_array($text, ['(', '[', '{'], true)) {
                $nesting++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $nesting--;
            } elseif ($nesting === 0 && $tokens[$at]->is(T_VARIABLE)) {
                $names[] = $text;
            }
        }

        return $names;
    }

    /**
     * Every name of `const [type] A = 1, B = 2;`: each identifier just before an `=`
     * at the declaration's own level (not inside a value's brackets).
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function constNames(array $tokens, int $at): array
    {
        $names = [];
        $last = null;
        $nesting = 0;
        for ($j = $at + 1, $count = count($tokens); $j < $count && !($nesting === 0 && $tokens[$j]->text === ';'); $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true)) {
                $nesting++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $nesting--;
            } elseif ($nesting === 0 && $text === '=' && $last !== null) {
                $names[] = $last;
            }
            if (!$tokens[$j]->is([T_WHITESPACE, T_COMMENT])) {
                $last = $tokens[$j]->is(T_STRING) ? $text : null;
            }
        }

        return $names;
    }
}
