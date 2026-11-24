<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;

/**
 * A project's event listener in the documented directory registers, and one the
 * registry does not take says so at boot.
 *
 * The module-structure guide puts a listener in its module's
 * Application/Handler/DomainListener/, but the registry took App\ classes only
 * under Event\DomainListener\: on the A12 guide project three listeners there
 * never ran, the event dispatched, and boot said nothing.
 */
final class ProjectListenerLocationTest extends TestCase
{
    private const DOCUMENTED = 'App\\Modules\\ListenerProbe\\Application\\Handler\\DomainListener\\ProbePaidListener';
    private const OLDER = 'App\\Modules\\ListenerProbe\\Application\\Event\\DomainListener\\ProbeShippedListener';
    private const ELSEWHERE = 'App\\Modules\\ListenerProbe\\Application\\Service\\ProbeRefundedListener';

    /** The process-wide collector as the test found it: BootDiagnostics::begin() replaces it. */
    private ?BootDiagnostics $diagnosticsBefore = null;

    protected function setUp(): void
    {
        $this->diagnosticsBefore = (new \ReflectionProperty(BootDiagnostics::class, 'current'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(BootDiagnostics::class, 'current'))->setValue(null, $this->diagnosticsBefore);
    }

    public static function setUpBeforeClass(): void
    {
        // Project classes, declared the way a module under src/modules/ declares them.
        $dir = sys_get_temp_dir() . '/project-listener-' . bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ([self::DOCUMENTED, self::OLDER, self::ELSEWHERE] as $class) {
            if (class_exists($class, false)) {
                continue;
            }
            $cut = (int) strrpos($class, '\\');
            $namespace = substr($class, 0, $cut);
            $short = substr($class, $cut + 1);
            $event = '\\' . ProjectListenerProbeEvent::class;
            file_put_contents("{$dir}/{$short}.php", "<?php\nnamespace {$namespace};\n"
                . "#[\\Semitexa\\Core\\Attribute\\AsEventListener(event: {$event}::class, execution: 'sync')]\n"
                . "final class {$short}\n{\n    public function handle({$event} \$event): void\n    {\n    }\n}\n");
            require "{$dir}/{$short}.php";
        }
        exec('rm -rf ' . escapeshellarg($dir));
    }

    #[Test]
    public function a_listener_in_the_documented_directory_is_registered_and_the_older_one_still_is(): void
    {
        $registry = new EventListenerRegistry($this->discoveryReturning([self::DOCUMENTED, self::OLDER]), new ModuleRegistry());

        self::assertSame([self::DOCUMENTED, self::OLDER], array_column($registry->getListeners(ProjectListenerProbeEvent::class), 'class'));
    }

    #[Test]
    public function a_listener_elsewhere_is_not_registered_and_boot_says_where_it_belongs(): void
    {
        $diagnostics = BootDiagnostics::begin();
        $registry = new EventListenerRegistry($this->discoveryReturning([self::ELSEWHERE]), new ModuleRegistry());

        self::assertSame([], $registry->getListeners(ProjectListenerProbeEvent::class));
        self::assertSame(
            ['Listener ' . self::ELSEWHERE . " is not in a listener directory, so it is not registered: put it in its module's Application/Handler/DomainListener/."],
            $this->registryWarnings($diagnostics),
        );
    }

    #[Test]
    public function a_registered_project_listener_raises_no_warning(): void
    {
        $diagnostics = BootDiagnostics::begin();
        $registry = new EventListenerRegistry($this->discoveryReturning([self::DOCUMENTED, self::OLDER]), new ModuleRegistry());

        self::assertSame([self::DOCUMENTED, self::OLDER], array_column($registry->getListeners(ProjectListenerProbeEvent::class), 'class'));
        self::assertSame([], $this->registryWarnings($diagnostics));
    }

    /** @return list<string> */
    private function registryWarnings(BootDiagnostics $diagnostics): array
    {
        return array_values(array_map(
            static fn ($warning): string => $warning->message,
            array_filter($diagnostics->getWarnings(), static fn ($warning): bool => $warning->component === 'EventListenerRegistry'),
        ));
    }

    /** @param list<string> $classes */
    private function discoveryReturning(array $classes): ClassDiscovery
    {
        return new class ($classes) extends ClassDiscovery {
            /** @param list<string> $classes */
            public function __construct(private readonly array $classes)
            {
            }

            public function initialize(): void
            {
            }

            public function findClassesWithAttribute(string $attributeClass): array
            {
                return $this->classes;
            }
        };
    }
}

final class ProjectListenerProbeEvent
{
}
