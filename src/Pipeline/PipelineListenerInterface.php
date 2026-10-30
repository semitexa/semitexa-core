<?php

declare(strict_types=1);

namespace Semitexa\Core\Pipeline;

/** @api */
interface PipelineListenerInterface
{
    public function handle(RequestPipelineContext $context): void;
}
