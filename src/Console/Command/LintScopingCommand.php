<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Attributes\AsEventListener;
use Semitexa\Core\Attributes\AsPipelineListener;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\AsService;
use Semitexa\Core\Attributes\ExecutionScoped;
use Semitexa\Core\Attributes\SatisfiesServiceContract;
use Semitexa\Core\Attributes\SatisfiesRepositoryContract;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify all execution-scoped classes have explicit #[ExecutionScoped] or handler/listener attribute.
 */
#[AsCommand(name: 'semitexa:lint:scoping', description: 'Verify execution-scoped classes have explicit scoping attributes')]
final class LintScopingCommand extends BaseCommand
{
    private const SCOPING_ATTRIBUTES = [
        ExecutionScoped::class,
        AsPayloadHandler::class,
        AsEventListener::class,
        AsPipelineListener::class,
    ];

    private const CONTAINER_MANAGED_ATTRIBUTES = [
        AsService::class,
        'Semitexa\\Orm\\Attribute\\AsRepository',
        SatisfiesServiceContract::class,
        SatisfiesRepositoryContract::class,
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lint: Scoping');

        $errors = [];
        $classesChecked = 0;

        // Collect all container-managed classes
        $classes = [];
        foreach (self::CONTAINER_MANAGED_ATTRIBUTES as $attrClass) {
            foreach (ClassDiscovery::findClassesWithAttribute($attrClass) as $class) {
                $classes[$class] = true;
            }
        }

        foreach (array_keys($classes) as $class) {
            $classesChecked++;
            try {
                $ref = new \ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }

            $shortName = $ref->getShortName();

            // Check if class name suggests it should be execution-scoped
            if (!str_contains($shortName, 'Handler') && !str_contains($shortName, 'Listener')) {
                continue;
            }

            // Check if it has an explicit scoping attribute
            $hasScoping = false;
            foreach (self::SCOPING_ATTRIBUTES as $attrClass) {
                if ($ref->getAttributes($attrClass) !== []) {
                    $hasScoping = true;
                    break;
                }
            }

            if (!$hasScoping) {
                $errors[] = "{$class}: Name contains 'Handler' or 'Listener' but lacks explicit scoping attribute. "
                    . "Add #[ExecutionScoped] if it needs per-execution state, or rename if it's truly worker-scoped.";
            }
        }

        if ($errors === []) {
            $io->success(sprintf('All %d container-managed classes have correct scoping.', $classesChecked));
            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        $io->error(sprintf('%d scoping issue(s) found.', count($errors)));
        return self::FAILURE;
    }
}
