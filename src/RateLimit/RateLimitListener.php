<?php

declare(strict_types=1);

namespace Semitexa\Core\RateLimit;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\RateLimit;
use Semitexa\Core\Exception\RateLimitException;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;

/**
 * Holds a payload's #[RateLimit]s before its handler runs.
 *
 * After authorization (priority 0), so a limit by user sees the signed-in
 * user, and a request refused for permissions never counts; before CSRF (10).
 * A re-run of a live region is the request already counted, so it passes.
 * With several limits, a request refused by one is taken back from those
 * that had already counted it.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: 2)]
final class RateLimitListener implements PipelineListenerInterface
{
    #[InjectAsMutable]
    protected RateLimiter $limiter;

    public function handle(RequestPipelineContext $context): void
    {
        if ($context->reRun) {
            return;
        }

        $counted = [];
        foreach ((new \ReflectionClass($context->requestDto))->getAttributes(RateLimit::class) as $attribute) {
            $limit = $attribute->newInstance();
            $decision = $this->limiter->attempt(
                $limit->bucket ?? $context->requestDto::class,
                $limit->limit,
                $limit->perSeconds,
                $limit->by,
            );
            if (!$decision->allowed) {
                foreach ($counted as $allowed) {
                    $this->limiter->release($allowed);
                }

                throw new RateLimitException($decision->retryAfter, $decision->limit);
            }
            $counted[] = $decision;
        }
    }
}
