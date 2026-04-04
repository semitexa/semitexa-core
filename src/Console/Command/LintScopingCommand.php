<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verify container-managed classes with #[InjectAsMutable] properties have explicit scoping attributes.
 */
#[AsCommand(name: 'semitexa:lint:scoping', description: 'Verify container-managed classes with #[InjectAsMutable] properties have explicit scoping attributes')]
final class LintScopingCommand extends BaseCommand
{
    public function __construct(
        private readonly ClassDiscovery $classDiscovery,
    ) {
        parent::__construct();
    }

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
            foreach ($this->classDiscovery->findClassesWithAttribute($attrClass) as $class) {
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

            // Check if class uses #[InjectAsMutable] on any property
            if (!$this->hasMutableInjection($ref)) {
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
                $errors[] = "{$class}: Uses #[InjectAsMutable] but lacks explicit scoping attribute. "
                    . "Add one of #[ExecutionScoped], #[AsPayloadHandler], #[AsEventListener], or #[AsPipelineListener] so the container applies a valid execution scope.";
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

    private function hasMutableInjection(\ReflectionClass $ref): bool
    {
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getAttributes(InjectAsMutable::class) !== []) {
                return true;
            }
        }
        return false;
    }
}
