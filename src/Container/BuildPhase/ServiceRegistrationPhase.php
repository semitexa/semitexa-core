<?php

declare(strict_types=1);

namespace Semitexa\Core\Container\BuildPhase;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Pipeline\AuthCheck;
use Semitexa\Core\Pipeline\HandleRequest;

/**
 * Registers all service classes in idToClass: payload handlers, #[AsService] classes,
 * ORM repositories, auth handlers, and pipeline listeners.
 *
 * Preconditions: context->classDiscovery, attributeDiscovery, pipelineListenerRegistry must be set.
 * Postconditions: context->idToClass populated with all service classes.
 */
final class ServiceRegistrationPhase implements BuildPhaseInterface
{
    public function execute(BuildContext $context): void
    {
        assert($context->classDiscovery !== null, 'ClassmapLoadPhase must run before ServiceRegistrationPhase');
        assert($context->handlerRegistry !== null, 'AttributeScanPhase must run before ServiceRegistrationPhase');
        assert($context->pipelineListenerRegistry !== null, 'RegistryBuildPhase must run before ServiceRegistrationPhase');

        // Payload handlers
        foreach ($context->handlerRegistry->getHandlerClassNames() as $handlerClass) {
            /** @var class-string $handlerClass */
            $context->idToClass[$handlerClass] = $handlerClass;
        }

        // #[AsService] classes
        foreach ($context->classDiscovery->findClassesWithAttribute(AsService::class) as $serviceClass) {
            /** @var class-string $serviceClass */
            $context->idToClass[$serviceClass] = $serviceClass;
        }

        // ORM repositories (optional package)
        if (
            class_exists(\Semitexa\Orm\Discovery\RepositoryDiscovery::class)
            && in_array('discoverRepositoryClasses', get_class_methods(\Semitexa\Orm\Discovery\RepositoryDiscovery::class), true)
        ) {
            /** @var list<class-string> $repositoryClasses */
            $repositoryClasses = \Semitexa\Orm\Discovery\RepositoryDiscovery::discoverRepositoryClasses($context->classDiscovery);
            foreach ($repositoryClasses as $repositoryClass) {
                $context->idToClass[$repositoryClass] = $repositoryClass;
            }
        }

        // Auth handlers (optional package)
        if (class_exists(\Semitexa\Auth\Attribute\AsAuthHandler::class)) {
            foreach ($context->classDiscovery->findClassesWithAttribute(\Semitexa\Auth\Attribute\AsAuthHandler::class) as $handlerClass) {
                /** @var class-string $handlerClass */
                $context->idToClass[$handlerClass] = $handlerClass;
            }
        }

        // Pipeline listeners
        foreach ([AuthCheck::class, HandleRequest::class] as $phaseClass) {
            foreach ($context->pipelineListenerRegistry->getListeners($phaseClass) as $meta) {
                $listenerClass = $meta['class'];
                if ($listenerClass === '') {
                    continue;
                }
                /** @var class-string $listenerClass */
                $context->idToClass[$listenerClass] = $listenerClass;
            }
        }
    }

    public function name(): string
    {
        return 'ServiceRegistration';
    }
}
