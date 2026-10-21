<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Exception\TypeMismatchException;
use Semitexa\Core\Http\PayloadHydrator;
use Semitexa\Core\Http\UploadedFile;
use Semitexa\Core\RequestFactory;

final class PayloadHydratorFilesTest extends TestCase
{
    #[Test]
    public function hydrator_passes_uploaded_file_through_to_typed_setter(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pt-');
        self::assertIsString($tmp);
        file_put_contents($tmp, 'native upload bytes');

        $request = RequestFactory::fromArray([
            'method' => 'POST',
            'uri' => '/upload',
            'post' => ['title' => 'Pipeline upload'],
            'files' => [
                'document' => [
                    'name' => 'readme.txt',
                    'type' => 'text/plain',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 19,
                ],
            ],
        ]);

        $dto = new HydratorFilesFixturePayload();
        PayloadHydrator::hydrate($dto, $request);

        self::assertSame('Pipeline upload', $dto->getTitle());
        self::assertNotNull($dto->getDocument());
        self::assertSame('readme.txt', $dto->getDocument()?->clientFilename);
        self::assertSame('native upload bytes', $dto->getDocument()?->getContents());

        @unlink($tmp);
    }

    #[Test]
    public function hydrator_leaves_setter_untouched_when_no_matching_file(): void
    {
        $request = RequestFactory::fromArray([
            'method' => 'POST',
            'uri' => '/upload',
            'post' => ['title' => 'No file here'],
            'files' => [],
        ]);

        $dto = new HydratorFilesFixturePayload();
        PayloadHydrator::hydrate($dto, $request);

        self::assertSame('No file here', $dto->getTitle());
        self::assertNull($dto->getDocument());
    }

    #[Test]
    public function two_files_or_text_under_a_single_file_field_name_the_field(): void
    {
        // They reached the setter and failed as a TypeError: a generic 422
        // that named no field.
        $tmp = tempnam(sys_get_temp_dir(), 'pt-');
        self::assertIsString($tmp);
        $entry = ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 0];

        foreach ([
            'two files' => ['files' => ['document' => [$entry, $entry]]],
            'text' => ['post' => ['document' => 'not a file']],
            'an empty value' => ['post' => ['document' => '']],
        ] as $case => $parts) {
            try {
                PayloadHydrator::hydrate(new HydratorFilesFixturePayload(), RequestFactory::fromArray(['method' => 'POST', 'uri' => '/upload'] + $parts));
                self::fail("{$case}: accepted");
            } catch (TypeMismatchException $e) {
                self::assertSame('document', $e->field, $case);
            }
        }
        @unlink($tmp);
    }
}

final class HydratorFilesFixturePayload
{
    private string $title = '';
    private ?UploadedFile $document = null;

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getDocument(): ?UploadedFile
    {
        return $this->document;
    }

    public function setDocument(UploadedFile $document): void
    {
        $this->document = $document;
    }
}
