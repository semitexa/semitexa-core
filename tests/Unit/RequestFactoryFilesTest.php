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

    #[Test]
    public function a_nested_field_name_keeps_its_files_under_the_name_the_form_gave_them(): void
    {
        // The shape Swoole 6.2 hands over for doc[avatar], doc[cv],
        // deep[x][y] and doc[photos][]: it used to be dropped entirely.
        $entry = fn (string $name): array => ['name' => $name, 'type' => 'text/plain', 'tmp_name' => $this->writeTmp('x'), 'error' => UPLOAD_ERR_OK, 'size' => 1];
        $files = RequestFactory::normalizeFiles([
            'doc' => ['avatar' => $entry('a.png'), 'cv' => $entry('cv.pdf'), 'photos' => [$entry('p1.jpg'), $entry('p2.jpg')]],
            'deep' => ['x' => ['y' => $entry('y.txt')]],
        ]);

        self::assertSame(['doc[avatar]', 'doc[cv]', 'doc[photos]', 'deep[x][y]'], array_keys($files));
        self::assertInstanceOf(UploadedFile::class, $files['doc[avatar]']);
        self::assertSame('cv.pdf', $files['doc[cv]'] instanceof UploadedFile ? $files['doc[cv]']->clientFilename : null);
        self::assertIsArray($files['doc[photos]']);
        self::assertCount(2, $files['doc[photos]']);
        self::assertSame('doc[avatar]', $files['doc[avatar]'] instanceof UploadedFile ? $files['doc[avatar]']->field : null);
        foreach ($files as $file) {
            foreach (is_array($file) ? $file : [$file] as $one) {
                @unlink($one->tmpPath);
            }
        }
    }

    /** doc[name] beside doc[avatar] read as the parallel-list shape: doc[avatar] was gone. */
    #[Test]
    public function a_nested_file_field_called_name_is_a_field_like_any_other(): void
    {
        $entry = fn (string $name): array => ['name' => $name, 'type' => 'text/plain', 'tmp_name' => $this->writeTmp('x'), 'error' => UPLOAD_ERR_OK, 'size' => 1];
        $files = RequestFactory::normalizeFiles([
            'doc' => ['avatar' => $entry('a.png'), 'name' => $entry('n.txt')],
        ]);

        self::assertSame(['doc[avatar]', 'doc[name]'], array_keys($files));
        self::assertSame('n.txt', $files['doc[name]'] instanceof UploadedFile ? $files['doc[name]']->clientFilename : null);
        foreach ($files as $file) {
            @unlink($file->tmpPath);
        }
    }

    #[Test]
    public function a_name_nested_past_any_form_is_not_followed(): void
    {
        $nested = ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/none', 'error' => UPLOAD_ERR_OK, 'size' => 1];
        for ($i = 0; $i < 40; $i++) {
            $nested = ['x' => $nested];
        }

        self::assertSame([], RequestFactory::normalizeFiles(['a' => $nested]));
    }

    private function writeTmp(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pt-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        return $tmp;
    }
}
