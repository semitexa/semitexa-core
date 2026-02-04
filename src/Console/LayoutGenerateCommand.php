<?php

declare(strict_types=1);

namespace Semitexa\Core\Console;

use Semitexa\Core\CodeGen\LayoutGenerator;

class LayoutGenerateCommand
{
    public static function run(array $argv): int
    {
        array_shift($argv); // script name

        try {
            if (empty($argv) || $argv[0] === '--all') {
                if (!empty($argv) && $argv[0] === '--all') {
                    array_shift($argv);
                }
                LayoutGenerator::generateAll();
                return 0;
            }

            $identifier = $argv[0];
            LayoutGenerator::generate($identifier);
            echo "✨ Layout copied for {$identifier}\n";
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, "❌ {$e->getMessage()}\n");
            self::printUsage();
            return 2;
        }
    }

    private static function printUsage(): void
    {
        echo <<<TXT
Usage:
  bin/semitexa layout:generate             Copy all module layouts into src/ (activates everything)
  bin/semitexa layout:generate --all       Explicit alias for the same behaviour
  bin/semitexa layout:generate <id>        Copy a single layout (handle or Module/handle)

Examples:
  bin/semitexa layout:generate
  bin/semitexa layout:generate login
  bin/semitexa layout:generate UserFrontend/login

TXT;
    }
}



