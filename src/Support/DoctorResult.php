<?php

declare(strict_types=1);

namespace Semitexa\Core\Support;

final readonly class DoctorResult
{
    /**
     * @param string $message One-line state description ("Imagick 3.8.1, WEBP/JPEG coders present")
     * @param string|null $hint Actionable fix shown on warn/fail ("apk add imagemagick-webp ...")
     */
    public function __construct(
        public DoctorStatus $status,
        public string $message,
        public ?string $hint = null,
    ) {
    }

    public static function pass(string $message): self
    {
        return new self(DoctorStatus::Pass, $message);
    }

    public static function warn(string $message, ?string $hint = null): self
    {
        return new self(DoctorStatus::Warn, $message, $hint);
    }

    public static function fail(string $message, ?string $hint = null): self
    {
        return new self(DoctorStatus::Fail, $message, $hint);
    }

    public static function skip(string $message): self
    {
        return new self(DoctorStatus::Skip, $message);
    }
}
