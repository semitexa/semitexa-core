<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Where worker:run reads `worker:processes` from: the lines go to the stream
 * it was given, and what the command says about a failed source still goes to
 * stderr, as it does from a terminal, instead of in among the lines.
 */
final class WorkerListingOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $errors;

    /** @param resource $stream */
    public function __construct($stream)
    {
        parent::__construct($stream);
        $this->errors = new StreamOutput(STDERR);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errors;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errors = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('The worker listing has no sections.');
    }
}
