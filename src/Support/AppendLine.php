<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

/**
 * Append to a file several worker processes write — a log, a journal — without
 * ever waiting for one of them.
 *
 * `file_put_contents(FILE_APPEND | LOCK_EX)` waits for the lock. Under
 * SWOOLE_HOOK_FILE that wait is slow (MEASURED on Swoole 6.2.3: 200 appends
 * contending for it took 3.67 s, against 0.013 s for this) and it cannot be
 * cancelled. The 72h soak of 2026-10-19 found a worker recycled by max_request
 * parked in exactly that wait, past max_wait_time, so Swoole killed it with the
 * requests it was still answering.
 *
 * The lock is TRIED, never waited for, and the data is written either way, in
 * one write(). What keeps a line whole is that single O_APPEND write — on a
 * local filesystem it lands at the end of the file in one piece — not the
 * lock; taking it when it is free only still keeps apart a writer that does
 * wait for it (ObservatoryJournal::writeDetached in the master). The worst case
 * is a line interleaved with another on a filesystem that does not honour
 * O_APPEND — which the readers already skip — against a worker held to its
 * forced kill.
 */
final class AppendLine
{
    /** @return bool true when every byte was written */
    public static function to(string $path, string $data): bool
    {
        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return false;
        }
        try {
            $locked = @flock($handle, LOCK_EX | LOCK_NB);
            $written = @fwrite($handle, $data);
            if ($locked) {
                @flock($handle, LOCK_UN);
            }

            return $written === strlen($data);
        } finally {
            @fclose($handle);
        }
    }
}
