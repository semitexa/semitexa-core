<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Queue\QueueInspection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue a dead letter again (attempts start over), or every dead letter of a
 * queue; --forget removes instead. A message that was never readable can only
 * be forgotten.
 */
#[AsCommand(name: 'queue:retry', description: 'Retry (or --forget) a dead letter by id, or --all of a queue.')]
final class QueueRetryCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected QueueInspection $inspection;

    protected function configure(): void
    {
        $this->setName('queue:retry')
            ->setDescription('Retry (or --forget) a dead letter by id, or --all of a queue.')
            ->addArgument('id', InputArgument::OPTIONAL, 'The dead letter id, as queue:failed prints it')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'The queue it failed on')
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'The transport (default: the configured one)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every dead letter of the queue')
            ->addOption('forget', null, InputOption::VALUE_NONE, 'Remove for good instead of retrying')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would happen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queue = (string) $input->getOption('queue');
        $id = (string) $input->getArgument('id');
        $all = (bool) $input->getOption('all');
        if ($queue === '' || ($id === '') === !$all) {
            $output->writeln('<error>Give --queue and either an id or --all.</error>');

            return Command::FAILURE;
        }

        $transport = (string) $input->getOption('transport');
        [$target] = $this->inspection->targets($transport !== '' ? $transport : null, $queue);
        $inspector = $target['inspector'];
        if ($inspector === null) {
            $output->writeln("<error>{$target['transport']} cannot be inspected: no dead letters to act on.</error>");

            return Command::FAILURE;
        }

        $forget = (bool) $input->getOption('forget');
        $ids = $all ? array_map(static fn ($l): string => $l->id, $inspector->deadLetters($queue, 1000)) : [$id];
        $done = 0;
        $failed = 0;
        foreach ($ids as $letterId) {
            if ((bool) $input->getOption('dry-run')) {
                $output->writeln(($forget ? 'would forget ' : 'would retry ') . $letterId);
                continue;
            }
            try {
                $ok = $forget ? $inspector->forgetDeadLetter($queue, $letterId) : $inspector->retryDeadLetter($queue, $letterId);
            } catch (\LogicException $e) {
                $output->writeln("<comment>{$letterId}: {$e->getMessage()} Use --forget.</comment>");
                $failed++;
                continue;
            } catch (\Throwable $e) {
                // One letter the transport could not act on must not stop --all
                // halfway without saying what was done.
                $output->writeln("<error>{$letterId}: {$e->getMessage()}</error>");
                $failed++;
                continue;
            }
            if ($ok) {
                $done++;
            } else {
                $output->writeln("<comment>{$letterId}: not a dead letter of {$queue}.</comment>");
                $failed++;
            }
        }

        $output->writeln(sprintf('<info>%s %d dead letter(s) of %s.</info>', $forget ? 'Forgot' : 'Queued again', $done, $queue));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
