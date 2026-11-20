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
     * @return array{path: string, chownTarget: string, exists: bool, writable: bool, ownerUid: ?int, ownerGid: ?int, mode: ?int, described: string, blocked: bool}
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
            'described' => $exists ? $path : dirname($path),
            'blocked' => false,
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
    public function a_directory_that_cannot_be_created_fails_and_names_the_parent(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', false, 0, 0755, false)], 1000, 1000);

        self::assertSame('fail', $result->status->value);
        self::assertStringStartsWith('/app/var/log does not exist and uid 1000 cannot create it in /app/var (owner 0:0, mode 0755)', $result->message);
        self::assertSame(self::FIX, $result->hint);
    }

    /** Review of core#253: the check creates nothing; a directory the start can create is no failure. */
    #[Test]
    public function a_missing_directory_the_server_can_create_passes_and_is_named(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/run', true, 1000, 0775, false)], 1000, 1000);

        self::assertSame('pass', $result->status->value);
        self::assertStringEndsWith("Not there yet, created at the server's start: /app/var/run.", $result->message);
    }

    #[Test]
    public function run_as_root_a_missing_directory_is_a_warning(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', true, 0, 0755, false)], 0, 0);

        self::assertSame('warn', $result->status->value);
        self::assertStringContainsString('/app/var/log does not exist (the server creates it at its start, as its own user)', $result->message);
        self::assertSame('Run system:doctor as the user the server runs as.', $result->hint);
    }

    #[Test]
    public function a_file_where_the_directory_has_to_be_is_to_be_moved_not_chowned(): void
    {
        $blocked = ['blocked' => true] + $this->directory('/app/var/log/swoole', false, 1000, 0644, false);

        $result = VarWritableDoctorCheck::verdict([$blocked], 1000, 1000);

        self::assertSame('fail', $result->status->value);
        self::assertSame("move '/app/var/log' away: it is a file where a directory has to be.", $result->hint);
    }

    /** Review of core#253: its own 0555 directory needs a chmod; a chown to the owner it has changes nothing. */
    #[Test]
    public function the_users_own_directory_without_the_write_bit_gets_the_chmod_hint(): void
    {
        $result = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', false, 1000, 0555)], 1000, 1000);

        self::assertSame('fail', $result->status->value);
        self::assertSame("chmod u+w '/app/var/log': uid 1000 owns it, the mode forbids the write.", $result->hint);
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

    /**
     * Review of core#253: the probe writes, so "not writable" under uid 0 is a
     * read-only mount or the like, and it stops the server's user as well. It
     * passed, because root was assumed to write anywhere.
     */
    #[Test]
    public function run_as_root_a_path_even_root_cannot_write_fails(): void
    {
        $result = VarWritableDoctorCheck::verdict(
            [$this->directory('/app/var/run', true, 1000), $this->directory('/app/var/log', false, 0, 0755)],
            0,
            0,
        );

        self::assertSame('fail', $result->status->value);
        self::assertSame(
            '/app/var/log is not writable even by root (owner 0:0, mode 0755): '
            . 'the server refuses to start, because it would hang on its log file.',
            $result->message,
        );
        self::assertSame('No owner or mode stops root: look for a read-only mount or an immutable flag on /app/var/log.', $result->hint);

        $missing = VarWritableDoctorCheck::verdict([$this->directory('/app/var/log', false, 1000, 0755, false)], 0, 0);
        self::assertSame('fail', $missing->status->value);
        self::assertSame(
            '/app/var/log does not exist and even root cannot create it in /app/var (owner 1000:1000, mode 0755): '
            . 'the server refuses to start, because it would hang on its log file.',
            $missing->message,
        );
        self::assertSame('No owner or mode stops root: look for a read-only mount or an immutable flag on /app/var.', $missing->hint);

        $blocked = ['blocked' => true] + $this->directory('/app/var/log', false, 1000, 0644);
        $file = VarWritableDoctorCheck::verdict([$blocked], 0, 0);
        self::assertSame('fail', $file->status->value);
        self::assertSame("move '/app/var/log' away: it is a file where a directory has to be.", $file->hint);
    }

    /** Review of core#253: Swoole opens its log for reading too, and a 0200 one passed the append probe. */
    #[Test]
    public function a_swoole_log_without_the_read_bit_fails_with_the_chmod_that_adds_it(): void
    {
        $log = ['open' => 'a+'] + $this->directory('/app/var/log/swoole.log', false, 1000, 0200);

        $result = VarWritableDoctorCheck::verdict([$log], 1000, 1000);

        self::assertSame('fail', $result->status->value);
        self::assertSame(
            '/app/var/log/swoole.log is not writable by uid 1000 (owner 1000:1000, mode 0200): '
            . 'the server refuses to start, because it would hang on its log file.',
            $result->message,
        );
        self::assertSame(
            "chmod u+r '/app/var/log/swoole.log': uid 1000 owns it, and the mode forbids the read Swoole opens its log with.",
            $result->hint,
        );
    }

    #[Test]
    public function it_is_declared_as_var_writable(): void
    {
        $attribute = (new \ReflectionClass(VarWritableDoctorCheck::class))->getAttributes(AsDoctorCheck::class)[0]->newInstance();

        self::assertSame('var.writable', $attribute->name);
        self::assertSame('semitexa/core', $attribute->package);
    }

    /**
     * Review of core#253, against the real file system: run as root (as this
     * suite is) the check made the missing log directory root:root 0755.
     */
    #[Test]
    public function running_it_creates_no_directory(): void
    {
        $parent = sys_get_temp_dir() . '/semitexa-doctor-' . bin2hex(random_bytes(6));
        mkdir($parent);
        $before = getenv('SWOOLE_LOG_FILE');
        putenv('SWOOLE_LOG_FILE=' . $parent . '/log/swoole.log');
        try {
            $result = (new VarWritableDoctorCheck())->run();

            self::assertDirectoryDoesNotExist($parent . '/log');
            self::assertSame([], array_values(array_diff((array) scandir($parent), ['.', '..'])), 'and the probe file is gone');
            // pass as the server's uid, warn as root (this suite may run as either): never fail, never empty.
            self::assertContains($result->status->value, ['pass', 'warn'], $result->message);
        } finally {
            putenv($before === false ? 'SWOOLE_LOG_FILE' : 'SWOOLE_LOG_FILE=' . $before);
            rmdir($parent);
        }
    }
}
