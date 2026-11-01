<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ThemeValue sat in ThemeLayer.php: an autoloader that follows PSR-4 finds it
 * only once ThemeLayer has loaded, and the release's BC gate (roave, which
 * locates classes by PSR-4) stopped on "ThemeValue could not be found".
 */
final class OneClassPerFileTest extends TestCase
{
    #[Test]
    public function every_class_like_in_src_sits_in_the_file_named_after_it(): void
    {
        $misplaced = [];
        $checked = [];
        $src = dirname(__DIR__, 3) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, 'cannot read ' . $file->getPathname());
            $path = substr($file->getPathname(), strlen($src) + 1);
            foreach (self::declaredIn($source) as $name) {
                $checked[$name] = $path;
                $short = substr((string) strrchr('\\' . $name, '\\'), 1);
                if ($short !== $file->getBasename('.php')) {
                    $misplaced[] = $name . ' in ' . $path;
                }
            }
        }

        // The scan reached the very pair that broke the gate, by full name and
        // path: a tree it never read, a tokenizer that finds nothing, or a
        // ThemeValue in another namespace cannot pass.
        self::assertSame('Tenant/Layer/ThemeValue.php', $checked['Semitexa\\Core\\Tenant\\Layer\\ThemeValue'] ?? null);
        self::assertSame('Tenant/Layer/ThemeLayer.php', $checked['Semitexa\\Core\\Tenant\\Layer\\ThemeLayer'] ?? null);
        self::assertSame([], $misplaced);
    }

    /** @return list<string> fully qualified: the namespace the file declares, then the name */
    private static function declaredIn(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $names = [];
        $namespace = '';
        foreach ($tokens as $i => $t) {
            if ($t->is(T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$i + 1]->text . '\\';
                continue;
            }
            // `Foo::class` and `new class` are not declarations.
            if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])
                && !($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW])
                && ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                $names[] = $namespace . $tokens[$i + 1]->text;
            }
        }

        return $names;
    }
}
