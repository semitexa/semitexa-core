<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Environment;

/** The .env reader follows dotenv conventions, so a file behaves the same here as in any other tool. */
final class EnvFileParsingTest extends TestCase
{
    #[Test]
    public function values_follow_dotenv_conventions(): void
    {
        $env = $this->parse(<<<'ENV'
            # full-line comment
              # indented comment with A=B inside
            CORS_ALLOW_ORIGIN=https://example.com # trusted origin
            COLOR=#fff
            HASH_IN_QUOTES="a#b" # trailing note
            SINGLE='literal \n $x'
            ESCAPED="say \"hi\""
            export EXPORTED=yes
            EMPTY=
            SPACED =  padded value  
            UNTERMINATED="open
            ENV);

        self::assertSame([
            'CORS_ALLOW_ORIGIN' => 'https://example.com',
            'COLOR' => '#fff',
            'HASH_IN_QUOTES' => 'a#b',
            'SINGLE' => 'literal \n $x',
            'ESCAPED' => 'say "hi"',
            'EXPORTED' => 'yes',
            'EMPTY' => '',
            'SPACED' => 'padded value',
            'UNTERMINATED' => '"open',
        ], $env);
    }

    /** @return array<string, string> */
    private function parse(string $contents): array
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        try {
            /** @var array<string, string> */
            return (new \ReflectionMethod(Environment::class, 'parseEnvFile'))->invoke(null, $file);
        } finally {
            unlink($file);
        }
    }
}
