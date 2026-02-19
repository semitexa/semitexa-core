<?php

namespace Semitexa\Core\Handler;

use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;

interface HttpHandlerInterface
{
    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface;
}