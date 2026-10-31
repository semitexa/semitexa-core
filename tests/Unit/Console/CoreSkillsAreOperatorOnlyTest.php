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
            file_put_contents($dir . '/Marked.php', "<?php\nnamespace Probe;\n\n#[AsAiSkill(operatorOnly: true, riskLevel: AiRiskLevel::Medium)]\nfinal class Marked {}\n");
            file_put_contents($dir . '/Plain.php', "<?php\nnamespace Probe;\n\n#[Other]\nfinal class Plain {}\n");

            self::assertSame(['Probe\Grouped' => false, 'Probe\Marked' => true], self::skills($dir));

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

    /** @return array<string, bool> */
    private static function skillsIn(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);
        $found = [];
        $namespace = '';
        $pending = null;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_NAMESPACE)) {
                for ($namespace = '', $i++; $i < $count && !in_array($tokens[$i]->text, [';', '{'], true); $i++) {
                    if ($tokens[$i]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $namespace = $tokens[$i]->text;
                    }
                }
                continue;
            }
            if ($t->is(T_ATTRIBUTE)) {
                // The whole #[...] group, however many lines and attributes it holds.
                $text = '';
                for ($open = 1; $open > 0 && ++$i < $count;) {
                    $open += $tokens[$i]->text === '[' ? 1 : ($tokens[$i]->text === ']' ? -1 : 0);
                    $text .= $tokens[$i]->text;
                }
                if (preg_match('/(?<![\w\\\\])(?:\\\\?Semitexa\\\\Llm\\\\Attribute\\\\)?AsAiSkill\s*\(/', $text) === 1) {
                    $pending = preg_match('/\boperatorOnly\s*:\s*true\b/i', $text) === 1;
                }
                continue;
            }
            if ($t->is(T_CLASS) && $pending !== null) {
                for ($j = $i + 1; $j < $count && !$tokens[$j]->is(T_STRING); $j++) {
                }
                $found[ltrim($namespace . '\\' . ($tokens[$j]->text ?? ''), '\\')] = $pending;
                $pending = null;
            }
        }

        return $found;
    }
}
