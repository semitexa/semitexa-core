<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Lifecycle;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\SandboxGuard;

/**
 * The flag that lets a replay stop outbound calls without knowing who makes
 * them.
 *
 * `ai:trace replay` already rolls its transaction back and captures queue
 * handoffs. What it could not reach was a SYNC listener calling out directly.
 * Having the runner swap each package's transport would put semitexa/dev in
 * the position of knowing every port in the ecosystem — and it could only ask
 * whether a package is installed with a runtime class check, the shape
 * `semitexa.explicitOptionalDependency` forbids.
 */
final class SandboxGuardTest extends TestCase
{
    protected function setUp(): void
    {
        SandboxGuard::reset();
    }

    protected function tearDown(): void
    {
        // Process-global: a test that left it armed would silently drop mail
        // for every test after it.
        SandboxGuard::reset();
    }

    #[Test]
    public function it_is_off_until_somebody_enters(): void
    {
        self::assertFalse(SandboxGuard::isActive());
        self::assertSame('', SandboxGuard::reason());
        self::assertSame([], SandboxGuard::withheldCalls());
    }

    #[Test]
    public function entering_carries_the_reason_a_port_can_quote(): void
    {
        SandboxGuard::enter('ai:trace replay');

        self::assertTrue(SandboxGuard::isActive());
        self::assertSame('ai:trace replay', SandboxGuard::reason());
    }

    /**
     * The half that keeps the sandbox honest in BOTH directions. Silently
     * dropping an outbound call would make a replay claim nothing happened
     * when a listener tried to email a customer — which is a finding.
     */
    #[Test]
    public function a_withheld_call_is_recorded_rather_than_dropped(): void
    {
        SandboxGuard::enter('replay');
        SandboxGuard::withhold('mail', ['driver' => 'smtp']);

        $withheld = SandboxGuard::withheldCalls();

        self::assertCount(1, $withheld);
        self::assertSame('mail', $withheld[0]['port']);
        self::assertSame(['driver' => 'smtp'], $withheld[0]['detail']);
        self::assertNotSame('', $withheld[0]['at']);
    }

    /** Entering again starts a fresh account rather than appending to the last run's. */
    #[Test]
    public function entering_clears_what_the_previous_run_withheld(): void
    {
        SandboxGuard::enter('first');
        SandboxGuard::withhold('mail');
        SandboxGuard::leave();

        SandboxGuard::enter('second');

        self::assertSame([], SandboxGuard::withheldCalls());
    }

    /**
     * Leaving must really let outbound calls through again: this is
     * process-global, so a guard left armed changes everything after it.
     */
    #[Test]
    public function leaving_disarms_it(): void
    {
        SandboxGuard::enter('replay');
        SandboxGuard::leave();

        self::assertFalse(SandboxGuard::isActive());
        self::assertSame('', SandboxGuard::reason());
    }
}
