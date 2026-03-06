<?php

declare(strict_types=1);

namespace Semitexa\Core\Console;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\NotFoundException;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Application as SymfonyApplication;
use ReflectionClass;

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Semitexa', '1.1.31');

        $container = ContainerFactory::get();
        $commandClasses = ClassDiscovery::findClassesWithAttribute(AsCommand::class);

        $commandsWithMeta = [];
        foreach ($commandClasses as $className) {
            try {
                $ref = new ReflectionClass($className);
                $attrs = $ref->getAttributes(AsCommand::class);
                if ($attrs === []) {
                    continue;
                }
                /** @var AsCommand $attr */
                $attr = $attrs[0]->newInstance();
                if (!is_subclass_of($className, Command::class)) {
                    continue;
                }
                $commandsWithMeta[] = ['class' => $className, 'attr' => $attr];
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] Console Application: skip command {$className}: " . $e->getMessage());
                }
            }
        }

        usort($commandsWithMeta, static fn (array $a, array $b): int => strcmp($a['attr']->name, $b['attr']->name));

        foreach ($commandsWithMeta as ['class' => $className, 'attr' => $attr]) {
            try {
                $command = $this->instantiateCommand($className, $container);
                if ($command === null) {
                    continue;
                }
                $command->setName($attr->name);
                if ($attr->description !== null) {
                    $command->setDescription($attr->description);
                }
                if ($attr->aliases !== []) {
                    $command->setAliases($attr->aliases);
                }
                $this->add($command);
            } catch (\Throwable $e) {
                if (Environment::getEnvValue('APP_DEBUG') === '1') {
                    error_log("[Semitexa] Console Application: could not register command {$attr->name} ({$className}): " . $e->getMessage());
                }
            }
        }
    }

    /**
     * @param class-string<Command> $className
     * @return Command|null null if dependencies are not available (e.g. optional package)
     */
    private function instantiateCommand(string $className, \Psr\Container\ContainerInterface $container): ?Command
    {
        $ref = new ReflectionClass($className);
        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            return new $className();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                return null;
            }
            $typeName = $type->getName();
            try {
                $args[] = $container->get($typeName);
            } catch (NotFoundException $e) {
                $resolved = $this->tryAutoInstantiate($typeName);
                if ($resolved === null) {
                    if (Environment::getEnvValue('APP_DEBUG') === '1') {
                        error_log("[Semitexa] Console Application: skip {$className}, container has no {$typeName}");
                    }
                    return null;
                }
                $args[] = $resolved;
            }
        }

        return new $className(...$args);
    }

    /**
     * Try to instantiate a concrete class that is not registered in the container.
     * Only works for classes with no required constructor parameters.
     */
    private function tryAutoInstantiate(string $className): ?object
    {
        try {
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || $ref->isInterface()) {
                return null;
            }
            $ctor = $ref->getConstructor();
            if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                return null;
            }
            return new $className();
        } catch (\Throwable) {
            return null;
        }
    }
}
