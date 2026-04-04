<?php

declare(strict_types=1);

namespace Semitexa\Core\Error;

final readonly class ErrorPageContext
{
    public function __construct(
        public int $statusCode,
        public string $reasonPhrase,
        public string $publicMessage,
        public string $requestPath,
        public string $requestMethod,
        public ?string $requestId,
        public bool $debugEnabled,
        public ?string $exceptionClass,
        public ?string $debugMessage,
        public ?string $trace,
        public ?string $originalRouteName,
    ) {}
}
