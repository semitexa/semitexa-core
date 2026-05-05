<?php

declare(strict_types=1);

namespace Semitexa\Core\Attribute;

use Attribute;
use Semitexa\Core\Auth\PayloadAccessType;

/**
 * Marks a payload as intentionally public — no user or service authentication.
 *
 * MUST NOT be combined with #[RequiresCapability], #[RequiresPermission], or
 * any other auth requirement. PayloadAccessPolicyResolver::assertValidMetadata
 * rejects such combinations at boot with the offending FQCN.
 *
 * "Publicly reachable" is NOT the same as "public access": a webhook receiver
 * is reachable from the public internet but is authenticated by a signature
 * — declare it #[AsServicePayload], not #[AsPublicPayload].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsPublicPayload extends AbstractPayloadRoute
{
    public function getAccessType(): PayloadAccessType
    {
        return PayloadAccessType::Public;
    }
}
