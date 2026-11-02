<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

use Semitexa\Core\Http\Response\ResourceResponse;

/** The form page, with what a handler wrote into it (InvalidPayloadPipelineTest). */
final class FormPage extends ResourceResponse
{
    /** @var array<string, mixed> */
    public array $shown = [];
}
