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
 * server:reload cannot change it.
 *
 * A project of its own (review, core#232): two listeners, one naming its attribute in lower case
 * (PHP matches attribute names case-insensitively), and a handler that must stay unread. Neither is
 * Composer's to autoload, like a module under src/modules. Each scan runs in a fresh PHP process.
 */
final class NamedAttributeDiscoveryTest extends TestCase
{
    private const FILES = [
        'BootListener.php'  => "<?php\nnamespace App\\Fixture;\nuse Semitexa\\Core\\Attribute\\AsServerLifecycleListener;\n#[AsServerLifecycleListener(phase: 'pre_start')]\nfinal class BootListener {}\n",
        'LowerListener.php' => "<?php\nnamespace App\\Fixture;\n#[\\Semitexa\\Core\\Attribute\\asserverlifecyclelistener(phase: 'pre_start')]\nfinal class LowerListener {}\n",
        'OrderHandler.php'  => "<?php\nnamespace App\\Fixture;\nfinal class OrderHandler {}\n",
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-named-discovery-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o755, true);
        mkdir($this->root . '/vendor/composer', 0o755, true);
        $map = [];
        foreach (self::FILES as $file => $source) {
            file_put_contents($this->root . '/src/' . $file, $source);
            $map['App\\Fixture\\' . basename($file, '.php')] = $this->root . '/src/' . $file;
        }
        file_put_contents($this->root . '/vendor/composer/autoload_classmap.php', '<?php return ' . var_export($map, true) . ';');
        file_put_contents($this->root . '/vendor/composer/autoload_psr4.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    // verify:accept-test-change the checkout-wide test read core's own src/ in a standalone checkout (review, core#232); this fixture project checks the same three things exactly
    #[Test]
    public function it_finds_the_listeners_a_full_scan_finds_and_reads_nothing_else_of_the_application(): void
    {
        $named = $this->discover('Semitexa\Core\Discovery\NamedAttributeDiscovery');
        $full = $this->discover('Semitexa\Core\Discovery\ClassDiscovery');

        $listeners = ['App\Fixture\BootListener', 'App\Fixture\LowerListener'];
        self::assertSame($listeners, $full['found']);
        self::assertSame($listeners, $named['found']);
        // The control: a full scan reads the handler, so the check below can fail.
        self::assertSame(['BootListener.php', 'LowerListener.php', 'OrderHandler.php'], $full['application']);
        self::assertSame(['BootListener.php', 'LowerListener.php'], $named['application']);
    }

    /**
     * @return array{found: list<string>, application: list<string>}
     */
    private function discover(string $discovery): array
    {
        $script = sprintf(
            <<<'PHP'
                define('SEMITEXA_PROJECT_ROOT', %s);
                require %s;
                $found = (new %s())->findClassesWithAttribute(%s);
                sort($found);
                $src = SEMITEXA_PROJECT_ROOT . '/src/';
                $application = array_map('basename', array_values(array_filter(get_included_files(), static fn (string $f): bool => str_starts_with($f, $src))));
                sort($application);
                echo json_encode(['found' => $found, 'application' => $application]);
                PHP,
            var_export($this->root, true),
            var_export(ProjectRoot::get() . '/vendor/autoload.php', true),
            '\\' . $discovery,
            var_export(AsServerLifecycleListener::class, true),
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $output = implode("\n", $out);
        self::assertSame(0, $code, $output);
        $decoded = json_decode((string) end($out), true);
        self::assertIsArray($decoded, $output);

        /** @var array{found: list<string>, application: list<string>} $decoded */
        return $decoded;
    }
}
