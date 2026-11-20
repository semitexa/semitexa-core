<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Application\Console\Command\SystemDoctorCommand;
use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Security\AppSecretDoctorCheck;
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

        // The table on request: a tester's output is no terminal, which gets the compact form.
        $exit = $tester->execute(['--table' => true]);

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

        $exit = $tester->execute(['--table' => true]);

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

    /**
     * tk-new-required-setting-reaches-prod-silently: an update run reads this report from a
     * subprocess and blocks on a FAIL of a check that names a setting, so the names must be
     * there for the check that declares them and an empty list for one that does not.
     */
    #[Test]
    public function jsonModeNamesTheSettingsEachCheckJudges(): void
    {
        $tester = $this->tester([AppSecretDoctorCheck::class, PassingDoctorCheck::class, ThrowingDoctorCheck::class]);

        $tester->execute(['--json' => true]);

        $data = json_decode($tester->getDisplay(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['checks']);
        $settings = array_column($data['checks'], 'settings', 'name');
        self::assertSame(
            ['app.secret' => ['APP_SECRET'], 'test.pass' => [], 'test.throws' => []],
            $settings,
        );
        $first = $data['checks'][0] ?? null;
        self::assertIsArray($first);
        self::assertSame(
            ['name', 'package', 'status', 'message', 'hint', 'settings'],
            array_keys($first),
            'the field is added last; the rest of a check is as it was',
        );
    }

    /**
     * tk-doctor-table-pads-to-230-columns (reported by the Guard project): read by an
     * installer, a log or an agent, the table cost about 7,000 characters for 24 rows,
     * 20 of them OK, each padded to the widest message. Off a terminal the doctor says
     * the counts, what is not OK with its fix, and the verdict.
     */
    #[Test]
    public function offATerminalOnlyWhatIsNotOkIsListed(): void
    {
        foreach ([[], ['--compact' => true]] as $options) {
            $tester = $this->tester([PassingDoctorCheck::class, FailingDoctorCheck::class]);
            self::assertSame(1, $tester->execute($options));
            self::assertSame(
                "System doctor: 1 pass, 0 warn, 1 fail, 0 skip\n"
                . "  FAIL test.fail (semitexa/test): broken thing\n"
                . "    fix: do the fix\n"
                . "Environment is NOT healthy.\n",
                $tester->getDisplay(),
                'options: ' . json_encode($options),
            );
        }

        $healthy = $this->tester([PassingDoctorCheck::class]);
        self::assertSame(0, $healthy->execute(['--compact' => true]));
        self::assertSame("System doctor: 1 pass, 0 warn, 0 fail, 0 skip\nEnvironment is healthy.\n", $healthy->getDisplay());
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
