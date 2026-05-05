<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\Core\RequestFactory;

final class RequestFactoryFilesTest extends TestCase
{
    #[Test]
    public function single_file_entry_produces_one_uploaded_file(): void
    {
        $tmp = $this->writeTmp('one');
        $files = RequestFactory::normalizeFiles([
            'avatar' => [
                'name' => 'photo.png',
                'type' => 'image/png',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => 3,
            ],
        ]);

        self::assertArrayHasKey('avatar', $files);
        self::assertInstanceOf(UploadedFile::class, $files['avatar']);
        /** @var UploadedFile $upload */
        $upload = $files['avatar'];
        self::assertSame('avatar', $upload->field);
        self::assertSame('photo.png', $upload->clientFilename);
        self::assertSame(3, $upload->size);
        @unlink($tmp);
    }

    #[Test]
    public function multi_file_entry_in_swoole_list_shape_is_exploded(): void
    {
        $a = $this->writeTmp('AAA');
        $b = $this->writeTmp('BB');

        $files = RequestFactory::normalizeFiles([
            'attachments' => [
                ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $a, 'error' => UPLOAD_ERR_OK, 'size' => 3],
                ['name' => 'b.txt', 'type' => 'text/plain', 'tmp_name' => $b, 'error' => UPLOAD_ERR_OK, 'size' => 2],
            ],
        ]);

        self::assertArrayHasKey('attachments', $files);
        $list = $files['attachments'];
        self::assertIsArray($list);
        self::assertCount(2, $list);
        self::assertSame('a.txt', $list[0]->clientFilename);
        self::assertSame('b.txt', $list[1]->clientFilename);
        @unlink($a);
        @unlink($b);
    }

    #[Test]
    public function multi_file_entry_in_parallel_array_shape_is_exploded(): void
    {
        $a = $this->writeTmp('AAA');
        $b = $this->writeTmp('BB');

        $files = RequestFactory::normalizeFiles([
            'attachments' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$a, $b],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [3, 2],
            ],
        ]);

        self::assertCount(2, $files['attachments']);
        self::assertSame('a.txt', $files['attachments'][0]->clientFilename);
        self::assertSame($b, $files['attachments'][1]->tmpPath);
        @unlink($a);
        @unlink($b);
    }

    #[Test]
    public function already_typed_uploaded_file_passes_through(): void
    {
        $tmp = $this->writeTmp('zzz');
        $existing = new UploadedFile('document', 'doc.pdf', 'application/pdf', 3, $tmp);
        $files = RequestFactory::normalizeFiles(['document' => $existing]);
        self::assertSame($existing, $files['document']);
        @unlink($tmp);
    }

    #[Test]
    public function empty_or_invalid_input_drops_silently(): void
    {
        self::assertSame([], RequestFactory::normalizeFiles([]));
        self::assertSame([], RequestFactory::normalizeFiles('not-an-array'));
        self::assertSame([], RequestFactory::normalizeFiles([
            '' => ['name' => 'ignored.txt', 'tmp_name' => '', 'type' => '', 'error' => 0, 'size' => 0],
            123 => ['name' => 'numeric-key.txt', 'tmp_name' => '', 'type' => '', 'error' => 0, 'size' => 0],
            'broken' => 'not-an-array-or-uploaded-file',
        ]));
    }

    #[Test]
    public function from_array_round_trip_preserves_typed_files(): void
    {
        $tmp = $this->writeTmp('payload');
        $request = RequestFactory::fromArray([
            'method' => 'POST',
            'uri' => '/upload',
            'files' => [
                'avatar' => [
                    'name' => 'pic.png',
                    'type' => 'image/png',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 7,
                ],
            ],
        ]);

        self::assertTrue($request->hasFile('avatar'));
        $file = $request->getFile('avatar');
        self::assertNotNull($file);
        self::assertSame('pic.png', $file->clientFilename);
        self::assertSame([], $request->getFiles('not-set'));
        @unlink($tmp);
    }

    private function writeTmp(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pt-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        return $tmp;
    }
}
