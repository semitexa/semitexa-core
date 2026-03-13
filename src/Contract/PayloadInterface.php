<?php

declare(strict_types=1);

namespace Semitexa\Core\Contract;

/**
 * @deprecated v2.0 — This marker interface is no longer required. Payload classes
 *             no longer need to implement it. Plain payload objects are supported,
 *             but TypedHandlerInterface handlers still require concrete class
 *             type hints in their handle() signature. Will be removed in v3.0.
 *
 * @see \Semitexa\Core\Contract\TypedHandlerInterface for the current handler contract
 */
interface PayloadInterface
{
}
