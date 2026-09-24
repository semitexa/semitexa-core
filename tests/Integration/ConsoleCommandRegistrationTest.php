<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Console\Application;
use Semitexa\Core\Discovery\BootDiagnostics;

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
    }
}
