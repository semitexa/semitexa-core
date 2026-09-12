<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LogOrigin;

/**
 * The slot core owns so that it need not know who can answer.
 *
 * The properties pinned here are the defensive ones. This sits on the path
 * every log line takes, including the lines written while everything else is
 * already going wrong, so the ways it must NOT behave matter more than the
 * happy path: it must cost nothing when nobody installed anything, and it must
 * never turn a resolver's failure into a lost log line.
 */
final class LogOriginTest extends TestCase
{
    /**
     * Emptied at BOTH ends, and the setUp half is not belt-and-braces.
     *
     * The slot is process-global by design, and RequestTracer::begin() installs
     * into it as a side effect — so any earlier test in the run that traced
     * anything leaves a resolver behind. Asserting the empty case without first
     * establishing it passes alone and fails in the suite, which is how this
     * was found.
     */
    protected function setUp(): void
    {
        LogOrigin::resolveWith(null);
    }

    protected function tearDown(): void
    {
        LogOrigin::resolveWith(null);
    }

    #[Test]
    public function nobody_is_asked_until_somebody_volunteers(): void
    {
        self::assertFalse(LogOrigin::isResolvable());
        self::assertNull(LogOrigin::current());
    }

    #[Test]
    public function the_installed_resolver_answers(): void
    {
        LogOrigin::resolveWith(static fn (): array => ['process' => 'p-17-abc', 'block' => 'pipeline.handler']);

        self::assertTrue(LogOrigin::isResolvable());
        self::assertSame(['process' => 'p-17-abc', 'block' => 'pipeline.handler'], LogOrigin::current());
    }

    #[Test]
    public function a_resolver_that_throws_costs_the_origin_and_nothing_else(): void
    {
        LogOrigin::resolveWith(static function (): array {
            throw new \RuntimeException('the observer broke');
        });

        self::assertNull(
            LogOrigin::current(),
            'a diagnostic that can take the log line with it is worse than no diagnostic',
        );
    }

    #[Test]
    public function an_empty_answer_is_no_answer(): void
    {
        // The dev-side resolver returns [] when it knows neither half. Letting
        // that through would put an empty 'origin' on every line in a project
        // that is not tracing.
        LogOrigin::resolveWith(static fn (): array => []);

        self::assertNull(LogOrigin::current());
    }

    #[Test]
    public function the_slot_can_be_emptied_again(): void
    {
        LogOrigin::resolveWith(static fn (): array => ['block' => 'gate']);
        LogOrigin::resolveWith(null);

        self::assertFalse(LogOrigin::isResolvable());
        self::assertNull(LogOrigin::current());
    }
}
