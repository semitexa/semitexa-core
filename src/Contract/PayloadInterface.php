<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * @deprecated v2.0 — This marker interface is no longer required. Payload classes
 *             no longer need to implement it. The pipeline and auth system now
 *             accept `object` typed parameters. Will be removed in v3.0.
 *
 * @see \Semitexa\Core\Contract\TypedHandlerInterface for the current handler contract
 */
interface PayloadInterface
{
}
