<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\AppendLine;

/**
 * Appending to a file other worker processes write must never wait for them.
 * The cross-process, hooked case — the one the soak found — is
 * AsyncJsonLoggerLockHeldTest; these pin the contract itself.
 */
final class AppendLineTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/semitexa-append-line-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    #[Test]
    public function it_appends_and_keeps_what_was_there(): void
    {
        self::assertTrue(AppendLine::to($this->path, "one\n"));
        self::assertTrue(AppendLine::to($this->path, "two\n"));

        self::assertSame("one\ntwo\n", file_get_contents($this->path));
    }

    /**
     * Another handle holds the lock — what another worker's write looks like
     * from here. Without hooks a waiting flock would block this process on a
     * lock it holds itself, forever; the test returning at all is the proof
     * it did not wait.
     */
    #[Test]
    public function a_held_lock_is_not_waited_for_and_the_line_is_written(): void
    {
        touch($this->path);
        $other = fopen($this->path, 'ab');
        self::assertIsResource($other);
        self::assertTrue(flock($other, LOCK_EX));

        try {
            $startedAt = microtime(true);
            self::assertTrue(AppendLine::to($this->path, "while held\n"));
            self::assertLessThan(0.5, microtime(true) - $startedAt);
        } finally {
            flock($other, LOCK_UN);
            fclose($other);
        }

        self::assertSame("while held\n", file_get_contents($this->path));
    }

    /** PHP writes in 8 KiB chunks by default; a log flush can be larger, and must arrive whole. */
    #[Test]
    public function data_larger_than_a_chunk_arrives_whole(): void
    {
        $data = str_repeat(str_repeat('x', 99) . "\n", 1000);

        self::assertTrue(AppendLine::to($this->path, $data));

        self::assertSame($data, file_get_contents($this->path));
    }

    #[Test]
    public function an_unopenable_path_says_so(): void
    {
        self::assertFalse(AppendLine::to(sys_get_temp_dir() . '/no-such-dir-' . bin2hex(random_bytes(4)) . '/file', "x\n"));
    }
}
