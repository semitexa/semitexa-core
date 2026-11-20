<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Server\VarWritableDoctorCheck;

/** What system:doctor says about the directories the server logs into (tk-root-owned-var-hangs-the-server). */
final class VarWritableDoctorCheckTest extends TestCase
{
    private const FIX = "chown -R 1000:1000 '/app/var' (as root), or start with bin/semitexa server:start, which reclaims ownership.";

    /**
     * @return array{path: string, chownTarget: string, exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int}
     */
    private function directory(string $path, bool $writable, int $ownerUid, int $mode = 0775, bool $exists = true): array
    {
        return [
            'path' => $path,
            'chownTarget' => '/app/var',
            'exists' => $exists,
            'writable' => $writable,
            'ownerUid' => $ownerUid,
            'ownerGid' => $ownerUid,
            'mode' => $mode,
        ];
    }

    #[Test]
    public function directories_the_user_owns_and_can_write_pass(): void
    {
        $result = VarWritableDoctorCheck::verdict(
            [$this->directory('/app/var/log', true, 1000), $this->directory('/app/var/run', true, 1000)],
            1000,
            1000,
        );

        self::assertSame('pass', $result->status->value);
        self::assertNull($result->hint);
    }

    #[Test]
    public function the_incident_fails_with_the_owner_the_mode_and_the_fix(): void
    {
        $result = VarWritableDoctorCheck::verdict(
            [$this->directory('/app/var/run', true, 1000), $this->directory('/app/var/log', false, 0)],
            1000,
            1000,
        );

        self::assertSame('fail', $result->status->value);
        self::assertStringStartsWith('/app/var/log is not writable by uid 1000 (owner 0:0, mode 0775)', $result->message);
        self::assertSame(self::FIX, $result->hint);
    }

    #[Test]
    public function a_directory_that_cannot_be_created_fails(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', false, 0, 0755, false)], 1000, 1000);

        self::assertSame('fail', $result->status->value);
        self::assertStringStartsWith('/app/var/log does not exist and cannot be created by uid 1000', $result->message);
        self::assertSame(self::FIX, $result->hint);
    }

    /** Writable through the group or a 777 today; the next file root creates in it is not. */
    #[Test]
    public function a_writable_directory_owned_by_another_user_fails(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', true, 0, 0777)], 1000, 1000);

        self::assertSame('fail', $result->status->value);
        self::assertStringStartsWith('/app/var/log is owned by 0:0, mode 0777, not by uid 1000', $result->message);
        self::assertSame(self::FIX, $result->hint);
    }

    /** An unwritable directory is the one to name, wherever it is in the list. */
    #[Test]
    public function not_writable_is_reported_before_a_foreign_owner(): void
    {
        $result = VarWritableDoctorCheck::verdict(
            [$this->directory('/app/var/run', true, 33), $this->directory('/app/var/log', false, 0)],
            1000,
            1000,
        );

        self::assertStringStartsWith('/app/var/log is not writable', $result->message);
    }

    /** Root can write anything, and "chown to 0" is the incident: run as root the check must not advise it. */
    #[Test]
    public function run_as_root_it_warns_that_it_cannot_judge_and_advises_no_chown(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', true, 1000)], 0, 0);

        self::assertSame('warn', $result->status->value);
        self::assertSame('Run system:doctor as the user the server runs as.', $result->hint);
        self::assertSame('pass', VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', true, 0)], 0, 0)->status->value);
    }

    #[Test]
    public function it_is_declared_as_var_writable_and_runs_against_the_real_directories(): void
    {
        $attribute = (new \ReflectionClass(VarWritableDoctorCheck::class))->getAttributes(AsDoctorCheck::class)[0]->newInstance();
        self::assertSame('var.writable', $attribute->name);
        self::assertSame('semitexa/core', $attribute->package);

        // The suite's own project: its var/log is where this very run logs.
        self::assertContains((new VarWritableDoctorCheck())->run()->status->value, ['pass', 'warn']);
    }
}
