<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

/**
 * The dead letter of a failed message could not be written. Thrown out of the
 * worker to the transport, which then hands the message back instead of
 * acknowledging it into nowhere; never caught as one more job failure.
 */
final class DeadLetterUnwritableException extends \RuntimeException
{
}
