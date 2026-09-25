<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use Semitexa\Core\Tests\Support\StaticState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ServiceContractRegistry;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Core\Support\ProjectRoot;

/**
 * A local module under src/modules/<Name> is autoloaded as both
 * `Semitexa\Modules\<Name>` and `App\Modules\<Name>` (LocalModuleAutoloadRegistrar),
 * but ModuleRegistry only knew the first. An `App\Modules\<Name>` class had no
 * module, so ServiceContractRegistry dropped its #[SatisfiesServiceContract].
 */
final class ModuleRegistryAppModulesNamespaceTest extends TestCase
{
    private string $root;

    private string|false $previousCwd;

    /** @var array{class: class-string, values: array<string, mixed>} */
    private array $projectRootState;

    protected function setUp(): void
    {
        $this->previousCwd = getcwd();
        $this->root = sys_get_temp_dir() . '/semitexa-app-modules-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/modules/blog-posts/src', 0777, true);
        file_put_contents($this->root . '/composer.json', '{}');
        file_put_contents(
            $this->root . '/src/modules/blog-posts/composer.json',
            '{"type": "semitexa-module", "autoload": {"psr-4": {"App\\\\Modules\\\\BlogPosts\\\\": "src/"}}}',
        );
        file_put_contents($this->root . '/src/modules/blog-posts/src/Greeter.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App\Modules\BlogPosts;
            interface GreeterInterface {}
            #[\Semitexa\Core\Attribute\SatisfiesServiceContract(of: GreeterInterface::class)]
            final class Greeter implements GreeterInterface {}
            PHP);
        if (!class_exists('App\\Modules\\BlogPosts\\Greeter', false)) {
            require $this->root . '/src/modules/blog-posts/src/Greeter.php';
        }

        $this->projectRootState = StaticState::snapshot(ProjectRoot::class);
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== false) {
            chdir($this->previousCwd);
        }
        // The root cached before this test, not whatever a fresh resolution
        // from the restored cwd happens to find.
        StaticState::restore($this->projectRootState);
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_local_module_class_is_found_under_both_of_its_namespaces(): void
    {
        $registry = new ModuleRegistry();

        self::assertSame('blog-posts', $registry->getModuleNameForClass('Semitexa\\Modules\\BlogPosts\\Greeter'));
        self::assertSame('blog-posts', $registry->getModuleNameForClass('App\\Modules\\BlogPosts\\Greeter'));
        self::assertNull($registry->getModuleNameForClass('App\\Modules\\BlogPostsArchive\\Greeter'));
    }

    #[Test]
    public function a_service_contract_in_an_app_modules_class_is_bound(): void
    {
        $discovery = new class () extends ClassDiscovery {
            public function initialize(): void
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return $attributeClass === \Semitexa\Core\Attribute\SatisfiesServiceContract::class
                    ? ['App\\Modules\\BlogPosts\\Greeter']
                    : [];
            }
        };

        $contracts = (new ServiceContractRegistry($discovery, new ModuleRegistry()))->getContracts();

        self::assertSame('App\\Modules\\BlogPosts\\Greeter', $contracts['App\\Modules\\BlogPosts\\GreeterInterface'] ?? null);
    }
}
