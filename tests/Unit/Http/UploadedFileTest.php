<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\UploadedFile;

final class UploadedFileTest extends TestCase
{
    #[Test]
    public function from_swoole_entry_populates_every_field(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pt-');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'hello upload');

        $file = UploadedFile::fromSwooleEntry('avatar', [
            'name' => 'photo.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => 12,
        ]);

        self::assertSame('avatar', $file->field);
        self::assertSame('photo.png', $file->clientFilename);
        self::assertSame('image/png', $file->clientMimeType);
        self::assertSame(12, $file->size);
        self::assertSame($tmp, $file->tmpPath);
        self::assertSame(UPLOAD_ERR_OK, $file->errorCode);
        self::assertTrue($file->isOk());
        self::assertFalse($file->hasError());
        self::assertSame('hello upload', $file->getContents());
        self::assertSame(hash('sha256', 'hello upload'), $file->sha256());

        @unlink($tmp);
    }

    #[Test]
    public function errored_upload_reports_status_and_does_not_read_bytes(): void
    {
        $file = UploadedFile::fromSwooleEntry('avatar', [
            'name' => 'photo.png',
            'type' => 'image/png',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]);

        self::assertTrue($file->hasError());
        self::assertFalse($file->isOk());
        self::assertSame('', $file->getContents());
        self::assertStringContainsString('upload_max_filesize', $file->errorMessage());
    }

    #[Test]
    public function from_swoole_entry_tolerates_partial_data(): void
    {
        $file = UploadedFile::fromSwooleEntry('blank', []);
        self::assertSame('', $file->clientFilename);
        self::assertSame('', $file->clientMimeType);
        self::assertSame('', $file->tmpPath);
        self::assertSame(0, $file->size);
        self::assertSame(UPLOAD_ERR_OK, $file->errorCode);
        // Without a tmp path the file is not OK — defensive fallback.
        self::assertFalse($file->isOk());
    }

    #[Test]
    public function safe_filename_check_rejects_traversal_and_separators(): void
    {
        self::assertTrue(UploadedFile::isSafeClientFilename('readme.md'));
        self::assertTrue(UploadedFile::isSafeClientFilename('photo.png'));
        self::assertFalse(UploadedFile::isSafeClientFilename(''));
        self::assertFalse(UploadedFile::isSafeClientFilename('../etc/passwd'));
        self::assertFalse(UploadedFile::isSafeClientFilename('a/b.txt'));
        self::assertFalse(UploadedFile::isSafeClientFilename('a\\b.txt'));
        self::assertFalse(UploadedFile::isSafeClientFilename("a\0b"));
        self::assertFalse(UploadedFile::isSafeClientFilename(str_repeat('a', 256)));
    }
}
