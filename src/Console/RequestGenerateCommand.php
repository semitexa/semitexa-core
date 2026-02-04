<?php

declare(strict_types=1);

namespace Semitexa\Core\Console;

use Semitexa\Core\CodeGen\RequestWrapperGenerator;

class RequestGenerateCommand
{
    public static function run(array $argv): int
    {
        array_shift($argv); // script name

        try {
            if (empty($argv) || $argv[0] === '--all') {
                if (!empty($argv) && $argv[0] === '--all') {
                    array_shift($argv);
                }
                RequestWrapperGenerator::generateAll();
                return 0;
            }

            $identifier = $argv[0];
            RequestWrapperGenerator::generate($identifier);
            echo "✨ Request wrapper generated for {$identifier}\n";
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, "❌ {$e->getMessage()}\n");
            return 2;
        }
    }

    private static function printUsage(): void
    {
        echo <<<TXT
Usage:
  bin/semitexa request:generate               Generate wrappers for all external requests
  bin/semitexa request:generate --all         Same as above (explicit)
  bin/semitexa request:generate <Request>     Generate/refresh a specific request

Examples:
  bin/semitexa request:generate
  bin/semitexa request:generate Semitexa\\User\\Application\\Request\\LoginApiRequest
  bin/semitexa request:generate LoginFormRequest

TXT;
    }
}

