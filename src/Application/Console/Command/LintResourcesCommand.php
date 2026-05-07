<?php

declare(strict_types=1);

namespace Semitexa\Core\Application\Console\Command;

use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Resource\Exception\MalformedResourceObjectException;
use Semitexa\Core\Resource\HandlerProvidedIncludeRegistry;
use Semitexa\Core\Resource\HandlerProvidedIncludeValidator;
use Semitexa\Core\Resource\Metadata\ResourceMetadataExtractor;
use Semitexa\Core\Resource\Metadata\ResourceMetadataRegistry;
use Semitexa\Core\Resource\Metadata\ResourceMetadataValidator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'lint:resources', description: 'Validate ResourceDTO classes, attributes, and the relation metadata graph.')]
final class LintResourcesCommand extends BaseCommand
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lint: Resource DTOs');

        $extractor = new ResourceMetadataExtractor();
        $registry  = ResourceMetadataRegistry::forTesting($extractor);

        $errors = [];

        try {
            $registry->buildFromDiscovery($this->classDiscovery);
        } catch (MalformedResourceObjectException $e) {
            $errors[] = $e->getMessage();
        }

        $count = count($registry->all());
        $io->writeln(sprintf('Discovered <info>%d</info> ResourceObject classes.', $count));

        if ($count > 0) {
            $validator      = ResourceMetadataValidator::forTesting($registry);
            $semanticErrors = $validator->validate();
            foreach ($semanticErrors as $e) {
                $errors[] = $e->getMessage();
            }

            // Phase 6c: validate `#[HandlerProvidesResourceIncludes]`
            // declarations against the resource metadata graph.
            $handlerProvidedRegistry  = HandlerProvidedIncludeRegistry::forTesting($this->classDiscovery);
            $handlerProvidedValidator = HandlerProvidedIncludeValidator::forTesting($registry);
            foreach ($handlerProvidedValidator->validate($handlerProvidedRegistry) as $message) {
                $errors[] = $message;
            }
        }

        if ($errors !== []) {
            $io->newLine();
            $io->error(sprintf('Found %d error(s) in Resource DTO declarations:', count($errors)));
            foreach ($errors as $message) {
                $io->writeln('  - ' . $message);
            }

            return self::FAILURE;
        }

        $io->success(sprintf('All %d Resource DTO declarations are valid.', $count));

        return self::SUCCESS;
    }
}
