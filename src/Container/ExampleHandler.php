<?php

declare(strict_types=1);

namespace Semitexa\Core\Container;

use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;

/**
 * Example handler demonstrating DI usage
 * This shows how to inject services into handlers
 */
class ExampleHandler
{
    public function __construct(
        private ExampleService $exampleService
    ) {}

    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface
    {
        // Use injected service
        $message = $this->exampleService->doSomething();
        echo "📝 Example handler: {$message}\n";
        
        return $resource;
    }
}

