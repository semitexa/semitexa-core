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
 *
 * Attributes are not inherited, and a payload with parts is a runtime
 * subclass of the class that declares the limit (PayloadFactory), so every
 * class the payload extends is read. Each limit found holds, the subclass's
 * and the parent's alike: extending a payload never loosens its limit. A
 * limit without a bucket counts under the class that declares it, so the
 * runtime subclass and the plain payload share one count.
 *
 * The same limit declared twice (same bucket, limit, window and kind of key,
 * on a parent and its child, say) is one counter, and is counted once: hit
 * twice by one request, a limit of 1 would refuse every request.
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
        $seen = [];
        for ($class = new \ReflectionClass($context->requestDto); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getAttributes(RateLimit::class) as $attribute) {
                $limit = $attribute->newInstance();
                $bucket = $limit->bucket ?? $class->getName();
                // What RateLimiter::attempt() makes one counter of.
                $counter = $bucket . '|' . $limit->limit . '|' . $limit->perSeconds . '|' . $limit->by->value;
                if (isset($seen[$counter])) {
                    continue;
                }
                $seen[$counter] = true;
                $decision = $this->limiter->attempt(
                    $bucket,
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
}
