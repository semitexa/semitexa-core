<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\LintResponsesCommand;
use Semitexa\Core\Support\ProjectRoot;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The rule under test is "user payload handlers must return ResourceInterface
 * DTOs" — NOT a ban on the sanctioned extension points. An exception mapper's
 * CONTRACT returns HttpResponse, and a consumer project may override the
 * ExceptionResponseMapperInterface binding from a module, so the lint must
 * recognise a mapper by what it declares, not by which vendor path it sits in
 * (#100).
 *
 * Fixture root via the documented seam: chdir() into a throwaway tree +
 * ProjectRoot::reset() (see ProjectRoot's candidate ordering comment).
 */
final class LintResponsesCommandTest extends TestCase
{
    private string $fixtureRoot;

    private string $previousCwd;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/lint-responses-' . bin2hex(random_bytes(6));
        $serviceDir = $this->fixtureRoot . '/src/modules/Demo/src/Application/Service';
        mkdir($serviceDir, 0777, true);
        file_put_contents($this->fixtureRoot . '/composer.json', '{}');

        $this->previousCwd = (string) getcwd();
        chdir($this->fixtureRoot);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        chdir($this->previousCwd);
        ProjectRoot::reset();

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixtureRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->fixtureRoot);
    }

    private function write(string $relative, string $php): void
    {
        file_put_contents($this->fixtureRoot . '/src/modules/Demo/src/Application/Service/' . $relative, $php);
    }

    private function run_(): CommandTester
    {
        $tester = new CommandTester(new LintResponsesCommand());
        $tester->execute([]);

        return $tester;
    }

    #[Test]
    public function a_module_exception_mapper_may_build_http_responses(): void
    {
        $this->write('LoginRedirectMapper.php', <<<'PHP'
<?php
namespace Demo;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\HttpResponse;
final class LoginRedirectMapper implements ExceptionResponseMapperInterface
{
    public function map(): HttpResponse
    {
        return HttpResponse::redirect('/login');
    }
}
PHP);

        $tester = $this->run_();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    #[Test]
    public function an_ordinary_service_is_still_flagged(): void
    {
        $this->write('SneakyService.php', <<<'PHP'
<?php
namespace Demo;
use Semitexa\Core\HttpResponse;
final class SneakyService
{
    public function out(): HttpResponse
    {
        return HttpResponse::json(['nope' => true]);
    }
}
PHP);

        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('SneakyService.php', $tester->getDisplay());
    }

    #[Test]
    public function a_comment_mentioning_the_interface_exempts_nothing(): void
    {
        $this->write('CommentedService.php', <<<'PHP'
<?php
namespace Demo;
use Semitexa\Core\HttpResponse;
// This service implements ExceptionResponseMapperInterface in spirit only.
final class CommentedService
{
    public function out(): HttpResponse
    {
        return HttpResponse::json(['nope' => true]);
    }
}
PHP);

        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('CommentedService.php', $tester->getDisplay());
    }

    #[Test]
    public function a_string_mentioning_the_interface_exempts_nothing(): void
    {
        $this->write('StringyService.php', <<<'PHP'
<?php
namespace Demo;
use Semitexa\Core\HttpResponse;
final class StringyService
{
    public function out(): HttpResponse
    {
        $label = 'implements ExceptionResponseMapperInterface';

        return HttpResponse::json(['label' => $label]);
    }
}
PHP);

        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('StringyService.php', $tester->getDisplay());
    }

    #[Test]
    public function the_mapper_exemption_does_not_leak_onto_neighbours(): void
    {
        $this->write('LoginRedirectMapper.php', <<<'PHP'
<?php
namespace Demo;
use Semitexa\Core\Contract\ExceptionResponseMapperInterface;
use Semitexa\Core\HttpResponse;
final class LoginRedirectMapper implements ExceptionResponseMapperInterface
{
    public function map(): HttpResponse
    {
        return HttpResponse::redirect('/login');
    }
}
PHP);
        $this->write('SneakyService.php', <<<'PHP'
<?php
namespace Demo;
use Semitexa\Core\HttpResponse;
final class SneakyService
{
    public function out(): HttpResponse
    {
        return HttpResponse::json(['nope' => true]);
    }
}
PHP);

        $tester = $this->run_();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('SneakyService.php', $tester->getDisplay());
        self::assertStringNotContainsString('LoginRedirectMapper.php', $tester->getDisplay());
    }
}
