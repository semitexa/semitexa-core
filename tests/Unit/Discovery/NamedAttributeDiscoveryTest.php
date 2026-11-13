<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The Swoole master finds its lifecycle listeners without loading the application
 * (tk-master-loads-no-app-classes): every class it loads is forked into each worker, where
 * server:reload cannot change it. Run in a fresh PHP process each, since this one has loaded half
 * the project by now.
 */
final class NamedAttributeDiscoveryTest extends TestCase
{
    #[Test]
    public function it_finds_the_listeners_a_full_scan_finds_and_loads_no_other_application_class(): void
    {
        $named = $this->discover('Semitexa\Core\Discovery\NamedAttributeDiscovery');
        $full = $this->discover('Semitexa\Core\Discovery\ClassDiscovery');

        self::assertNotSame([], $full['found'], 'the project has lifecycle listeners to find');
        self::assertSame($full['found'], $named['found']);
        // The control: a full scan loads far more of the application, so the check below can fail.
        self::assertGreaterThan(count($named['listeners']) + 50, count($full['application']), 'a full scan loads the application');
        // Of the application, only its own listeners: they must be read to know their phase.
        self::assertSame($named['listeners'], $named['application']);
        self::assertLessThan(intdiv($full['files'], 10), $named['files'], 'a tenth of the files at most');
    }

    /**
     * @return array{found: list<string>, application: list<string>, listeners: list<string>, files: int}
     */
    private function discover(string $discovery): array
    {
        $root = ProjectRoot::get();
        $script = sprintf(
            <<<'PHP'
                require %s;
                $found = (new %s())->findClassesWithAttribute(%s);
                sort($found);
                $src = %s . '/src/';
                $files = get_included_files();
                $listeners = array_values(array_filter(array_map(static fn (string $c): string => (string) (new \ReflectionClass($c))->getFileName(), $found), static fn (string $f): bool => str_starts_with($f, $src)));
                $application = array_values(array_filter($files, static fn (string $f): bool => str_starts_with($f, $src)));
                sort($listeners);
                sort($application);
                echo json_encode(['found' => $found, 'application' => $application, 'listeners' => $listeners, 'files' => count($files)]);
                PHP,
            var_export($root . '/vendor/autoload.php', true),
            '\\' . $discovery,
            var_export(AsServerLifecycleListener::class, true),
            var_export($root, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $output = implode("\n", $out);
        self::assertSame(0, $code, $output);
        $decoded = json_decode((string) strrchr("\n" . $output, "\n") ?: $output, true);
        self::assertIsArray($decoded, $output);

        /** @var array{found: list<string>, application: list<string>, listeners: list<string>, files: int} $decoded */
        return $decoded;
    }
}
