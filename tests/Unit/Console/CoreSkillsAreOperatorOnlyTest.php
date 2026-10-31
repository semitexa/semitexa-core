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
 */
final class CoreSkillsAreOperatorOnlyTest extends TestCase
{
    #[Test]
    public function every_core_skill_is_marked_operator_only(): void
    {
        $src = dirname(__DIR__, 3) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^#\[AsAiSkill\(/m', $source) !== 1) {
                continue;
            }
            $class = 'Semitexa\\Core\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($src) + 1, -4));
            self::assertTrue(class_exists($class), $class);
            // By name: the attribute's class lives in semitexa/llm, which core does not require.
            foreach ((new \ReflectionClass($class))->getAttributes('Semitexa\\Llm\\Attribute\\AsAiSkill') as $attribute) {
                $found[$class] = $attribute->getArguments()['operatorOnly'] ?? false;
            }
        }
        ksort($found);

        self::assertSame([
            'Semitexa\Core\Application\Console\Command\CacheClearCommand' => true,
            'Semitexa\Core\Application\Console\Command\ContractsListCommand' => true,
        ], $found);
    }
}
