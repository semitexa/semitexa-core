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
    /**
     * Run the container-dependent wiring a CLI process needs.
     *
     * A command builds the same container a worker does and then does real work
     * with it, but none of the worker's lifecycle ever ran — so anything wired
     * at WorkerStartAfterContainer was simply absent here. That is how a skill
     * run from a terminal wrote a row whose change event reached nobody, while
     * the same skill through the console behaved. Its own phase, because worker
     * listeners start timers and bind servers that a one-shot process must not
     * inherit.
     */
    private static function bootLifecycle(\Psr\Container\ContainerInterface $container): void
    {
        try {
            $registry = $container->get(\Semitexa\Core\Server\Lifecycle\ServerLifecycleRegistry::class);
            if (!$registry instanceof \Semitexa\Core\Server\Lifecycle\ServerLifecycleRegistry) {
                return;
            }

            $invoker = new \Semitexa\Core\Server\Lifecycle\ServerLifecycleInvoker($registry);
            $invoker->invokePhase(
                \Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase::ConsoleStartAfterContainer,
                new \Semitexa\Core\Server\Lifecycle\ServerLifecycleContext(
                    server: null,
                    workerId: null,
                    environment: $container->get(\Semitexa\Core\Environment::class),
                    container: $container instanceof \Semitexa\Core\Container\SemitexaContainer ? $container : null,
                ),
                true,
            );
        } catch (\Throwable) {
            // Boot wiring is opportunistic: a command must still run on an
            // install where one listener is unhappy.
        }
    }

    public function __construct()
    {
        parent::__construct('Semitexa', '1.1.31');

        $container = ContainerFactory::get();
        self::bootLifecycle($container);
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
                    // Said, not silent: a class that declares a command and cannot
                    // be one is a mistake nothing else would ever report.
                    BootDiagnostics::current()->skip(
                        'Console',
                        "Skip {$className}: #[AsCommand] on a class that does not extend " . Command::class,
                    );
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
     * services — via #[InjectAsReadonly] on protected properties — and are
     * created with a plain `new $className()`.
     *
     * Constructor DI is no longer resolved (the last framework commands moved
     * off it on 2026-09-30). A command that still asks for constructor
     * arguments is skipped with a diagnostic naming the fix, rather than a
     * bare "Too few arguments".
     *
     * @param class-string<Command> $className
     * @return Command|null null if dependencies are not available
     */
    private function instantiateCommand(string $className, SemitexaContainer $container): ?Command
    {
        $ctor = (new ReflectionClass($className))->getConstructor();
        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            BootDiagnostics::current()->skip(
                'Console',
                "Skip {$className}: it takes its dependencies through the constructor, which console boot does not resolve. "
                    . 'Declare them as protected properties with #[InjectAsReadonly] and drop the constructor.',
            );

            return null;
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
