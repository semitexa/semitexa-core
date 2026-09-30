<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Console\Application;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;

/**
 * Every discovered #[AsCommand] must reach the console.
 *
 * Console boot turns a command it cannot build into a BootDiagnostics skip,
 * which in the default verbosity is one summary line on stderr. That is how
 * layout:generate — the command SSR's own "layout not activated" page tells the
 * user to run — was absent from the console while nothing failed: its
 * constructor asked for a class the container does not register.
 *
 * A WHOLE-INSTALL gate, on purpose: discovery finds commands from every
 * installed package, so this goes red for a command outside core that cannot
 * be built in this install — in the release clone too, which installs
 * packages dev does not. That is the case worth catching: a command that
 * vanishes only where it ships.
 */
final class ConsoleCommandRegistrationTest extends TestCase
{
    #[Test]
    public function every_discovered_command_registers(): void
    {
        // begin() replaces the process-wide collector; put the previous one
        // back so a later test reads its own warnings, not these.
        $collector = new \ReflectionProperty(BootDiagnostics::class, 'current');
        $previous = $collector->getValue();

        try {
            BootDiagnostics::begin();

            $application = new Application();

            // Read current() only now: building the container inside the console
            // may begin() a fresh collector, orphaning one taken before it.
            $diagnostics = BootDiagnostics::current();

            $skipped = array_map(
                static fn ($w): string => $w->message,
                array_values(array_filter(
                    $diagnostics->getWarnings(),
                    static fn ($w): bool => $w->component === 'Console',
                )),
            );
            self::assertSame([], $skipped, 'Console boot skipped commands it discovered.');
            self::assertTrue($application->has('layout:generate'));
        } finally {
            $collector->setValue(null, $previous);
        }
    }

    #[Test]
    public function a_command_asking_for_constructor_arguments_is_skipped_with_the_fix_named(): void
    {
        $collector = new \ReflectionProperty(BootDiagnostics::class, 'current');
        $previous = $collector->getValue();

        try {
            BootDiagnostics::begin();
            $application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
            $instantiate = new \ReflectionMethod(Application::class, 'instantiateCommand');

            $command = $instantiate->invoke($application, ConstructorDiCommandFixture::class, ContainerFactory::get());

            self::assertNull($command);
            $messages = array_map(static fn ($w): string => $w->message, BootDiagnostics::current()->getWarnings());
            self::assertCount(1, $messages);
            self::assertStringContainsString(ConstructorDiCommandFixture::class, $messages[0]);
            self::assertStringContainsString('#[InjectAsReadonly]', $messages[0]);
        } finally {
            $collector->setValue(null, $previous);
        }
    }
}

/** Not #[AsCommand]: discovery must not find it, the test above hands it over. */
final class ConstructorDiCommandFixture extends Command
{
    public function __construct(public readonly ModuleRegistry $modules)
    {
        parent::__construct('fixture:constructor-di');
    }
}
