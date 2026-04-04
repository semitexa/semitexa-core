<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\CodeGen\LayoutGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'layout:generate', description: 'Copy module layouts into src/')]
class LayoutGenerateCommand extends BaseCommand
{
    public function __construct(
        private readonly LayoutGenerator $layoutGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('layout:generate')
            ->setDescription('Copy module layouts into src/')
            ->addArgument('layout', InputArgument::OPTIONAL, 'Specific layout handle (optional)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Generate all layouts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $layout = $input->getArgument('layout');
        $all = $input->getOption('all');

        try {
            if ($all || !is_string($layout) || $layout === '') {
                $this->layoutGenerator->generateAll($io);
            } else {
                $this->layoutGenerator->generate($layout, $io);
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($output->isVerbose()) {
                $io->error($e->getTraceAsString());
            }
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
