<?php

declare(strict_types=1);

namespace Semitexa\Core\Feature;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\PipelineListenerInterface;
use Semitexa\Core\Pipeline\RequestPipelineContext;

/**
 * Hands the request's flags to CurrentFeatureFlags so a template can ask
 * feature('x'). First in AuthCheck: the flags read the user when asked, and a
 * 401/403 page rendered after authorization refuses must see them too.
 */
#[AsPipelineListener(phase: AuthCheck::class, priority: -10)]
final class CurrentFeatureFlagsListener implements PipelineListenerInterface
{
    #[InjectAsMutable(optional: true)]
    protected FeatureFlagsInterface $flags;

    public function handle(RequestPipelineContext $context): void
    {
        if (isset($this->flags)) {
            CurrentFeatureFlags::set($this->flags);
        }
    }
}
