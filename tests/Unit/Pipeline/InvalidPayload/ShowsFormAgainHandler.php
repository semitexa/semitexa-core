<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

use Semitexa\Core\Contract\HandlesInvalidPayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;

/** Shows the form again with its errors and the submitted name (InvalidPayloadPipelineTest). */
final class ShowsFormAgainHandler implements TypedHandlerInterface, HandlesInvalidPayloadInterface
{
    public function handle(FormPayload $payload, FormPage $page): FormPage
    {
        FormLog::$calls[] = 'handle';

        return $page;
    }

    public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface
    {
        FormLog::$calls[] = 'invalid';
        if ($payload instanceof FormPayload && $resource instanceof FormPage) {
            $resource->shown = ['name' => $payload->name, 'errors' => $errors, 'status' => $resource->getStatusCode()];
        }

        return $resource;
    }
}
