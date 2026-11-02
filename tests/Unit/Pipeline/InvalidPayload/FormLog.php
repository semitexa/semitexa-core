<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

/** What the handlers of InvalidPayloadPipelineTest were called with. */
final class FormLog
{
    /** @var list<string> */
    public static array $calls = [];
}
