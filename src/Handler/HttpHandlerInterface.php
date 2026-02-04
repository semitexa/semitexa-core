<?php

namespace Semitexa\Core\Handler;

use Semitexa\Core\Contract\RequestInterface;
use Semitexa\Core\Contract\ResponseInterface;

interface HttpHandlerInterface
{
    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface;
}