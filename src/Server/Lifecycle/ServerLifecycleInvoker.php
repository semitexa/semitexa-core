<?php

declare(strict_types=1);

namespace Semitexa\Core\Server\Lifecycle;

use ReflectionClass;
use Semitexa\Core\Container\ContainerFactory;

final class ServerLifecycleInvoker
{
    public function invokePhase(ServerLifecyclePhase $phase, ServerLifecycleContext $context, bool $containerAvailable = false): void
    {
        ServerLifecycleRegistry::ensureBuilt();

        foreach (ServerLifecycleRegistry::getListeners($phase->value) as $meta) {
            $listener = $this->resolveListener($phase, $meta, $containerAvailable);
            if (!$listener instanceof ServerLifecycleListenerInterface) {
                continue;
            }

            try {
                $listener->handle($context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(sprintf(
                    'Server lifecycle listener "%s" failed in phase "%s": %s',
                    $meta['class'],
                    $phase->value,
                    $e->getMessage(),
                ), 0, $e);
            }
        }
    }

    /**
     * @param array{class: string, phase: string, priority: int, requiresContainer: bool} $meta
     */
    private function resolveListener(ServerLifecyclePhase $phase, array $meta, bool $containerAvailable): ?object
    {
        if (!$containerAvailable) {
            if ($meta['requiresContainer']) {
                throw new \RuntimeException(sprintf(
                    'Lifecycle listener "%s" requires the container but phase "%s" is running without container access.',
                    $meta['class'],
                    $phase->value,
                ));
            }
            return $this->instantiateWithoutContainer($meta['class']);
        }

        if ($meta['requiresContainer']) {
            $container = ContainerFactory::get();
            if ($container->has($meta['class'])) {
                return $container->get($meta['class']);
            }

            return $container instanceof \Semitexa\Core\Container\SemitexaContainer
                ? $container->resolve($meta['class'])
                : null;
        }

        return $this->instantiateWithoutContainer($meta['class']);
    }

    private function instantiateWithoutContainer(string $class): object
    {
        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            throw new \RuntimeException(sprintf(
                'Lifecycle listener "%s" cannot be instantiated without the container because it has required constructor dependencies.',
                $class,
            ));
        }

        return $ref->newInstance();
    }
}
