<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\SystemDoctorCommand;
use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Support\DoctorResult;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Local stub: pins the discovered check set AND no-ops initialize() so the
 * test never touches the real classmap.
 */
final class StubDoctorDiscovery extends ClassDiscovery
{
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
}

#[AsDoctorCheck(name: 'test.pass', package: 'semitexa/test')]
final class PassingDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        return DoctorResult::pass('all good');
    }
}

#[AsDoctorCheck(name: 'test.fail', package: 'semitexa/test')]
final class FailingDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        return DoctorResult::fail('broken thing', 'do the fix');
    }
}

#[AsDoctorCheck(name: 'test.throws', package: 'semitexa/test')]
final class ThrowingDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        throw new \RuntimeException('probe exploded');
    }
}

final class SystemDoctorCommandTest extends TestCase
{
    #[Test]
    public function healthyChecksExitZeroAndRenderTheTable(): void
    {
        $tester = $this->tester([PassingDoctorCheck::class]);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertStringContainsString('test.pass', $tester->getDisplay());
        self::assertStringContainsString('healthy', $tester->getDisplay());
    }

    #[Test]
    public function aFailingCheckFailsTheCommandAndShowsTheHint(): void
    {
        $tester = $this->tester([PassingDoctorCheck::class, FailingDoctorCheck::class]);

        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('do the fix', $tester->getDisplay());
        self::assertStringContainsString('NOT healthy', $tester->getDisplay());
    }

    #[Test]
    public function aThrowingCheckBecomesOneFailedRowInsteadOfACrash(): void
    {
        $tester = $this->tester([ThrowingDoctorCheck::class, PassingDoctorCheck::class]);

        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('probe exploded', $tester->getDisplay());
        self::assertStringContainsString('test.pass', $tester->getDisplay(), 'other checks still run');
    }

    #[Test]
    public function jsonModeEmitsTheEnvelopeWithExitCode(): void
    {
        $tester = $this->tester([FailingDoctorCheck::class]);

        $exit = $tester->execute(['--json' => true]);

        self::assertSame(1, $exit);
        $data = json_decode($tester->getDisplay(), true);
        self::assertSame('semitexa.system-doctor/v1', $data['artifact']);
        self::assertFalse($data['healthy']);
        self::assertSame('test.fail', $data['checks'][0]['name']);
    }

    #[Test]
    public function noDiscoveredChecksIsAWarningNotAFailure(): void
    {
        $tester = $this->tester([]);

        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No doctor checks discovered', $tester->getDisplay());
    }

    /**
     * @param list<string> $checkClasses
     */
    private function tester(array $checkClasses): CommandTester
    {
        $command = new SystemDoctorCommand();
        $property = new \ReflectionProperty(SystemDoctorCommand::class, 'classDiscovery');
        $property->setValue($command, new StubDoctorDiscovery($checkClasses));

        return new CommandTester($command);
    }
}
