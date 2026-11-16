<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\ProjectRoot;

/**
 * One ClassDiscovery instance answers for the project root it is asked under.
 *
 * Its own classmap and attribute answers used to outlive a change of root,
 * while the shared caches are keyed by the root of the moment: the worker
 * container, built once per process, was first built inside a test that
 * chdirs into an empty fixture root, and back in the real root the same
 * instance answered #[AsCommand] from the empty map — and wrote that empty
 * answer into the real root's shared bucket. Every console built afterwards
 * in that process found no commands (ContractsListCommandTest, failing only
 * after AiMakeItCommandTest; tk-contracts-list-test-order-dependent).
 */
final class DiscoveryFollowsProjectRootTest extends TestCase
{
    private string $realRoot = '';
    private string $cwd = '';
    private string $empty = '';

    protected function setUp(): void
    {
        ClassDiscovery::resetSharedCache();
        $this->cwd = (string) getcwd();
        $this->realRoot = ProjectRoot::get();
        $this->empty = sys_get_temp_dir() . '/discovery-root-' . bin2hex(random_bytes(4));
        // A root of its own, as ProjectRoot recognises one: composer.json beside src/modules.
        mkdir($this->empty . '/src/modules', 0777, true);
        file_put_contents($this->empty . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        ProjectRoot::reset();
        ClassDiscovery::resetSharedCache();
        exec('rm -rf ' . escapeshellarg($this->empty));
    }

    #[Test]
    public function an_instance_read_under_one_root_answers_the_next_root_from_its_own_map(): void
    {
        $discovery = new ClassDiscovery();

        $this->enter($this->empty);
        self::assertSame($this->empty, ProjectRoot::get(), 'the precondition: the fixture root is the project root now');
        self::assertSame([], $discovery->findClassesWithAttribute(AsCommand::class), 'an empty root declares no command');

        $this->enter($this->cwd);
        self::assertSame($this->realRoot, ProjectRoot::get());
        $commands = $discovery->findClassesWithAttribute(AsCommand::class);

        self::assertNotSame([], $commands, 'the real root answered from the empty root\'s map');
        self::assertContains(\Semitexa\Core\Application\Console\Command\LintDiCommand::class, $commands);
        $shared = (new ReflectionProperty(ClassDiscovery::class, 'sharedAttributeCaches'))->getValue();
        self::assertSame($commands, $shared[$this->realRoot][AsCommand::class] ?? null, 'the real root\'s shared bucket holds the real answer, not the empty one');
    }

    private function enter(string $dir): void
    {
        chdir($dir);
        ProjectRoot::reset();
    }
}
