<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Core is a composer-plugin, not a module, so the tenant skill scope finds no
 * module for its skills and hides them from every tenant. That decided the
 * right thing by accident: cache:clear and contracts:list act for the whole
 * install. Each core skill now says operatorOnly itself, and a new one must too.
 *
 * Read from the tokens, not through reflection: getArguments() evaluates every
 * argument, and the risk and confirmation enums live in semitexa/llm, which
 * core does not require.
 */
final class CoreSkillsAreOperatorOnlyTest extends TestCase
{
    #[Test]
    public function every_core_skill_is_marked_operator_only(): void
    {
        self::assertSame([
            'Semitexa\Core\Application\Console\Command\CacheClearCommand' => true,
            'Semitexa\Core\Application\Console\Command\ContractsListCommand' => true,
        ], self::skills(dirname(__DIR__, 3) . '/src'));
    }

    /**
     * The reading itself: a grouped, qualified or indented attribute is found,
     * and a file that cannot be read fails the scan instead of being skipped.
     */
    #[Test]
    public function the_scan_reads_every_form_and_fails_on_an_unread_file(): void
    {
        $dir = sys_get_temp_dir() . '/core-skills-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/Grouped.php', "<?php\nnamespace Probe;\n\n  #[Other, \\Semitexa\\Llm\\Attribute\\AsAiSkill(\n      allowed: true,\n  )]\nfinal class Grouped {}\n");
            file_put_contents($dir . '/Marked.php', "<?php\nnamespace Probe;\n\nuse Semitexa\\Llm\\Attribute\\AsAiSkill;\n\n#[AsAiSkill(operatorOnly: true, riskLevel: AiRiskLevel::Medium)]\nfinal class Marked {}\n");
            file_put_contents($dir . '/Plain.php', "<?php\nnamespace Probe;\n\n#[Other]\nfinal class Plain {}\n");
            // review: an alias, a grouped import, and the words inside a string or a comment.
            file_put_contents($dir . '/Aliased.php', "<?php\nnamespace Probe;\n\nuse Semitexa\\Llm\\Attribute\\AsAiSkill as CoreSkill;\n\n#[CoreSkill(operatorOnly: false, summary: 'operatorOnly: true')]\nfinal class Aliased {}\n");
            file_put_contents($dir . '/GroupImport.php', "<?php\nnamespace Probe;\n\nuse Semitexa\\Llm\\Attribute\\{AsAiPersona, AsAiSkill as Skill};\n\n#[Skill(/* operatorOnly: true */ summary: 'x')]\nfinal class GroupImport {}\n");
            // Same short name, another class: not a skill.
            file_put_contents($dir . '/Elsewhere.php', "<?php\nnamespace Probe;\n\n#[AsAiSkill(operatorOnly: true)]\nfinal class Elsewhere {}\n");

            self::assertSame(['Probe\Aliased' => false, 'Probe\GroupImport' => false, 'Probe\Grouped' => false, 'Probe\Marked' => true], self::skills($dir));

            symlink($dir . '/nowhere.php', $dir . '/Broken.php');
            $failed = null;
            try {
                self::skills($dir);
            } catch (\RuntimeException $e) {
                $failed = $e->getMessage();
            }
            self::assertSame('cannot read ' . $dir . '/Broken.php', $failed);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /**
     * Every class carrying #[AsAiSkill(...)] => whether that attribute says
     * operatorOnly: true.
     *
     * @return array<string, bool>
     */
    private static function skills(string $root): array
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
            $found += self::skillsIn($source);
        }
        ksort($found);

        return $found;
    }

    /**
     * Read from the tokens: an attribute's name resolves through the file's
     * imports (an alias, a grouped one), and operatorOnly is the attribute's
     * own named argument, so the words inside a string or a comment, which
     * are tokens of their own, never count.
     *
     * @return array<string, bool>
     */
    private static function skillsIn(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $count = count($tokens);
        $found = [];
        $namespace = '';
        $imports = [];
        $pending = null;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_NAMESPACE)) {
                for ($namespace = '', $i++; $i < $count && !in_array($tokens[$i]->text, [';', '{'], true); $i++) {
                    $namespace .= $tokens[$i]->text;
                }
                continue;
            }
            if ($t->is(T_USE) && $pending === null && $found === [] && !self::insideClass($tokens, $i)) {
                $i = self::readImport($tokens, $i + 1, $imports);
                continue;
            }
            if ($t->is(T_ATTRIBUTE)) {
                $i = self::readAttributeGroup($tokens, $i + 1, $namespace, $imports, $pending);
                continue;
            }
            if ($t->is(T_CLASS) && $pending !== null) {
                $found[ltrim($namespace . '\\' . ($tokens[$i + 1]->text ?? ''), '\\')] = $pending;
                $pending = null;
            }
        }

        return $found;
    }

    /**
     * One #[...] group from the token after `#[`; sets $pending when it holds
     * AsAiSkill, to whether that attribute says operatorOnly: true.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @return int the index of the closing `]`
     */
    private static function readAttributeGroup(array $tokens, int $i, string $namespace, array $imports, ?bool &$pending): int
    {
        $skill = false;
        $depth = 0;
        for ($count = count($tokens); $i < $count; $i++) {
            $t = $tokens[$i];
            if (in_array($t->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($t->text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($depth === 0 && $t->text === ',') {
                $skill = false;
            } elseif ($depth === 0 && $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $skill = self::resolve($t, $namespace, $imports) === 'Semitexa\\Llm\\Attribute\\AsAiSkill';
                if ($skill) {
                    $pending = false;
                }
            } elseif ($skill && $depth === 1 && $t->is(T_STRING) && $t->text === 'operatorOnly'
                && ($tokens[$i + 1]->text ?? '') === ':') {
                $pending = strtolower($tokens[$i + 2]->text ?? '') === 'true';
            }
        }

        return $i;
    }

    /**
     * A `use` from the token after it: plain, aliased or grouped.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports alias => full name
     * @return int the index of its `;`
     */
    private static function readImport(array $tokens, int $i, array &$imports): int
    {
        $prefix = '';
        $name = null;
        $alias = null;
        for ($count = count($tokens); $i < $count && $tokens[$i]->text !== ';'; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_NAME_QUALIFIED) || $t->is(T_NAME_FULLY_QUALIFIED) || $t->is(T_STRING)) {
                if ($alias === '') {
                    $alias = $t->text;
                } else {
                    $name = ltrim($t->text, '\\');
                }
            } elseif ($t->is(T_NS_SEPARATOR) && ($tokens[$i + 1]->text ?? '') === '{') {
                $prefix = (string) $name . '\\';
                $name = null;
            } elseif ($t->is(T_AS)) {
                $alias = '';
            } elseif (in_array($t->text, [',', '}'], true)) {
                self::addImport($imports, $prefix, $name, $alias);
                $name = $alias = null;
            }
        }
        self::addImport($imports, $prefix, $name, $alias);

        return $i;
    }

    /** @param array<string, string> $imports */
    private static function addImport(array &$imports, string $prefix, ?string $name, ?string $alias): void
    {
        if ($name === null || $name === '') {
            return;
        }
        $full = $prefix . $name;
        $imports[$alias !== null && $alias !== '' ? $alias : substr($full, (int) strrpos('\\' . $full, '\\'))] = $full;
    }

    /** @param array<string, string> $imports */
    private static function resolve(\PhpToken $name, string $namespace, array $imports): string
    {
        if ($name->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name->text, '\\');
        }
        $first = explode('\\', $name->text, 2);
        if (isset($imports[$first[0]])) {
            return $imports[$first[0]] . (isset($first[1]) ? '\\' . $first[1] : '');
        }

        return ltrim($namespace . '\\' . $name->text, '\\');
    }

    /**
     * Whether $i sits inside a class body, where `use` imports a trait.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function insideClass(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = 0; $j < $i; $j++) {
            $depth += $tokens[$j]->text === '{' ? 1 : ($tokens[$j]->text === '}' ? -1 : 0);
        }

        return $depth > 0;
    }
}
