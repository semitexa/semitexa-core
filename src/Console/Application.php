<?php

declare(strict_types=1);

namespace Semitexa\Core\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\Exception\InjectionException;
use Semitexa\Core\Container\SemitexaContainer;
use Semitexa\Core\Discovery\BootDiagnostics;
use Semitexa\Core\Discovery\ClassDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Application as SymfonyApplication;
use ReflectionClass;

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Semitexa', '1.1.31');

        $container = ContainerFactory::get();
        /** @var ClassDiscovery $classDiscovery */
        $classDiscovery = $container->get(ClassDiscovery::class);
        $commandClasses = $classDiscovery->findClassesWithAttribute(AsCommand::class);

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
                BootDiagnostics::current()->skip('Console', "Skip command {$className}: " . $e->getMessage(), $e);
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
                BootDiagnostics::current()->skip('Console', "Could not register command {$attr->name} ({$className}): " . $e->getMessage(), $e);
            }
        }
    }

    /**
     * Instantiate a #[AsCommand] class and hand it to the container for
     * property injection. Commands declare their dependencies exactly like
     * services — via #[InjectAsReadonly] on protected properties — so the
     * construction path is trivial:
     *
     *   1. Constructor must have zero required parameters. Commands are not
     *      container-managed framework objects (they live on Symfony's
     *      Application), so we cannot do constructor DI on them the way the
     *      container does for #[AsService]. Required constructor params are
     *      therefore a signal that a command is still using the pre-attribute
     *      DI style and is skipped with a diagnostic.
     *   2. Plain `new $className()`.
     *   3. Container applies #[InjectAsReadonly] property injection.
     *
     * @param class-string<Command> $className
     * @return Command|null null if dependencies are not available
     */
    private function instantiateCommand(string $className, SemitexaContainer $container): ?Command
    {
        $ref = new ReflectionClass($className);
        $ctor = $ref->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            $resolved = $container->get($className);

            return $resolved instanceof Command ? $resolved : null;
        }

        try {
            /** @var Command $command */
            $command = new $className();
            $container->injectInto($command);
            return $command;
        } catch (InjectionException $e) {
            BootDiagnostics::current()->skip('Console', "Skip {$className}: " . $e->getMessage(), $e);
            return null;
        }
    }
}
