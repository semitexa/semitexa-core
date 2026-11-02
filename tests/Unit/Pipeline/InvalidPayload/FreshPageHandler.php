<?php

declare(strict_types=1);

namespace Semitexa\Core\Tests\Unit\Pipeline\InvalidPayload;

use Semitexa\Core\Contract\HandlesInvalidPayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;

/** Answers an invalid payload with a fresh page left at 200 (InvalidPayloadPipelineTest). */
final class FreshPageHandler implements TypedHandlerInterface, HandlesInvalidPayloadInterface
{
    public function handle(FormPayload $payload, FormPage $page): FormPage
    {
        return $page;
    }

    public function invalid(object $payload, array $errors, ResourceInterface $resource): ResourceInterface
    {
        return new FormPage();
    }
}
