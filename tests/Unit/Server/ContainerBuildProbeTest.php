<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Server\ContainerBuildProbe;

/**
 * A container that cannot build stops server:start, now that the master no longer builds it and
 * every worker would throw in its own start instead (tk-master-loads-no-app-classes).
 */
final class ContainerBuildProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-build-probe-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_build_that_throws_is_reported_with_what_it_printed(): void
    {
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\nthrow new \\RuntimeException('two services claim PaymentGatewayInterface');\n");

        self::assertStringContainsString('two services claim PaymentGatewayInterface', (string) ContainerBuildProbe::failure($this->root));
    }

    #[Test]
    public function a_build_that_completes_is_no_failure(): void
    {
        // A stand-in for the project: the probe only asks that the build returns.
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\nnamespace Semitexa\\Core\\Container { final class ContainerFactory { public static function create(): void {} } }\n");

        self::assertNull(ContainerBuildProbe::failure($this->root));
    }
}
