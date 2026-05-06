<?php

declare(strict_types=1);

namespace Semitexa\Core\Http;

/**
 * Immutable value object describing a single uploaded file.
 *
 * Built by {@see RequestFactory} from the multipart payload Swoole hands us.
 * Handlers receive instances directly through the standard payload hydration
 * path — a Payload setter typed as `UploadedFile $foo` is filled with one of
 * these whenever a multipart field with the matching name carries a file.
 *
 * Mirrors PHP's UPLOAD_ERR_* constants so handler/validation code can react
 * uniformly to truncated, oversized, or missing tmp uploads.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $field,
        public string $clientFilename,
        public string $clientMimeType,
        public int $size,
        public string $tmpPath,
        public int $errorCode = UPLOAD_ERR_OK,
    ) {
    }

    public function isOk(): bool
    {
        return $this->errorCode === UPLOAD_ERR_OK
            && $this->tmpPath !== '';
    }

    public function hasError(): bool
    {
        return $this->errorCode !== UPLOAD_ERR_OK;
    }

    /**
     * Human-readable description of the upload's PHP error code.
     */
    public function errorMessage(): string
    {
        return match ($this->errorCode) {
            UPLOAD_ERR_OK         => 'OK',
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE form directive.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
            default               => 'Unknown upload error.',
        };
    }

    /**
     * Read the file's bytes off the temporary path.
     *
     * Returns the empty string when the upload errored or the temp file is
     * already gone. Handlers that need streaming should read $tmpPath directly.
     */
    public function getContents(): string
    {
        if (!$this->isOk() || !is_file($this->tmpPath)) {
            return '';
        }
        $bytes = @file_get_contents($this->tmpPath);
        return is_string($bytes) ? $bytes : '';
    }

    /**
     * SHA-256 of the file's bytes, useful as a cheap integrity probe in tests
     * and for log entries. Returns the empty string for errored uploads.
     */
    public function sha256(): string
    {
        $bytes = $this->getContents();
        return $bytes === '' ? '' : hash('sha256', $bytes);
    }

    /**
     * Returns true when the supplied filename has no path separators, no
     * traversal segments, and no embedded null byte. The check is intentional:
     * `clientFilename` comes from the user agent and must never be trusted as
     * a path component without validation.
     */
    public static function isSafeClientFilename(string $name): bool
    {
        if ($name === '' || strlen($name) > 255) {
            return false;
        }
        if (preg_match('#[\\\\/\\x00]#', $name) === 1) {
            return false;
        }
        if (str_contains($name, '..')) {
            return false;
        }
        return true;
    }

    /**
     * Build an UploadedFile from a Swoole / $_FILES-shaped entry. Supports
     * the single-file shape (`['name' => string, ...]`) only — fan-out for
     * multi-file fields is the caller's job, see RequestFactory.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromSwooleEntry(string $field, array $entry): self
    {
        $name = self::stringField($entry, 'name');
        $mime = self::stringField($entry, 'type');
        $tmp = self::stringField($entry, 'tmp_name');
        $errorRaw = $entry['error'] ?? UPLOAD_ERR_OK;
        $error = is_int($errorRaw) ? $errorRaw : (int) $errorRaw;
        $sizeRaw = $entry['size'] ?? 0;
        $size = is_int($sizeRaw) ? $sizeRaw : (int) $sizeRaw;
        if ($size < 0) {
            $size = 0;
        }

        return new self(
            field: $field,
            clientFilename: $name,
            clientMimeType: $mime,
            size: $size,
            tmpPath: $tmp,
            errorCode: $error,
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function stringField(array $entry, string $key): string
    {
        $value = $entry[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
