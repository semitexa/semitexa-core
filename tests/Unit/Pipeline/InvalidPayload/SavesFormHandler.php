<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

use Semitexa\Core\Contract\TypedHandlerInterface;

/** A second handler of the route, which only a valid request reaches (InvalidPayloadPipelineTest). */
final class SavesFormHandler implements TypedHandlerInterface
{
    public function handle(FormPayload $payload, FormPage $page): FormPage
    {
        FormLog::$calls[] = 'save';

        return $page;
    }
}
