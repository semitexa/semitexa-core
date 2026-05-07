<?php

declare(strict_types=1);

namespace Semitexa\Core\Resource\Exception;

use Semitexa\Core\Exception\DomainException;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Resource\RenderProfile;

/**
 * Thrown when a renderer is asked to produce a profile it does not support.
 * v1 ships only RenderProfile::Json.
 */
final class UnsupportedRenderProfileException extends DomainException
{
    public function __construct(
        private readonly RenderProfile $requested,
        private readonly string $rendererClass,
    ) {
        parent::__construct(sprintf(
            'Render profile "%s" is not supported by renderer %s.',
            $requested->value,
            $rendererClass,
        ));
    }

    public function getStatusCode(): HttpStatus
    {
        return HttpStatus::NotAcceptable;
    }

    public function getErrorContext(): array
    {
        return [
            'requested_profile' => $this->requested->value,
            'renderer'          => $this->rendererClass,
        ];
    }
}
