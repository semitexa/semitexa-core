<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Contract\RequestInterface;
use Semitexa\Core\Contract\ResponseInterface;

/**
 * Example handler demonstrating DI usage
 * This shows how to inject services into handlers
 */
class ExampleHandler
{
    public function __construct(
        private ExampleService $exampleService
    ) {}

    public function handle(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Use injected service
        $message = $this->exampleService->doSomething();
        echo "📝 Example handler: {$message}\n";
        
        return $response;
    }
}

