<?php

declare(strict_types=1);

namespace Semitexa\Core\Console;

use Symfony\Component\Console\Application as SymfonyApplication;
use Semitexa\Core\Console\Command\ServerStartCommand;
use Semitexa\Core\Console\Command\ServerStopCommand;
use Semitexa\Core\Console\Command\ServerRestartCommand;
use Semitexa\Core\Console\Command\RequestGenerateCommand;
use Semitexa\Core\Console\Command\ResponseGenerateCommand;
use Semitexa\Core\Console\Command\LayoutGenerateCommand;
use Semitexa\Core\Console\Command\QueueWorkCommand;
use Semitexa\Core\Console\Command\UserCreateCommand;
use Semitexa\Core\Console\Command\TestHandlerCommand;
use Semitexa\Core\Console\Command\InitCommand;
use Semitexa\Core\Console\Command\ContractsListCommand;
use Semitexa\Core\Console\Command\CacheClearCommand;
use Semitexa\Core\Console\Command\RegistrySyncCommand;
use Semitexa\Core\Console\Command\RegistrySyncPayloadsCommand;
use Semitexa\Core\Console\Command\RegistrySyncResourcesCommand;
use Semitexa\Core\Console\Command\RegistrySyncContractsCommand;

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Semitexa', '1.1.2');
        
        $commands = [
            new InitCommand(),
            new ContractsListCommand(),
            new CacheClearCommand(),
            new RegistrySyncCommand(),
            new RegistrySyncPayloadsCommand(),
            new RegistrySyncResourcesCommand(),
            new RegistrySyncContractsCommand(),
            new ServerStartCommand(),
            new ServerStopCommand(),
            new ServerRestartCommand(),
            new RequestGenerateCommand(),
            new ResponseGenerateCommand(),
            new LayoutGenerateCommand(),
            new QueueWorkCommand(),
            new UserCreateCommand(),
            new TestHandlerCommand(),
        ];

        // Add ORM commands if available and OrmManager is registered
        $container = \Semitexa\Core\Container\ContainerFactory::get();
        $hasOrmManager = $container->has(\Semitexa\Orm\OrmManager::class);
        if ($hasOrmManager && class_exists(\Semitexa\Orm\Console\Command\OrmSyncCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\OrmSyncCommand(
                $container->get(\Semitexa\Orm\OrmManager::class)
            );
        }
        if ($hasOrmManager && class_exists(\Semitexa\Orm\Console\Command\OrmDiffCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\OrmDiffCommand(
                $container->get(\Semitexa\Orm\OrmManager::class)
            );
        }
        if ($hasOrmManager && class_exists(\Semitexa\Orm\Console\Command\OrmSeedCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\OrmSeedCommand(
                $container->get(\Semitexa\Orm\OrmManager::class)
            );
        }
        if ($hasOrmManager && class_exists(\Semitexa\Orm\Console\Command\OrmStatusCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\OrmStatusCommand(
                $container->get(\Semitexa\Orm\OrmManager::class)
            );
        }

        // Add Tenancy commands if available
        if (class_exists(\Semitexa\Tenancy\CLI\TenantListCommand::class)) {
            $commands[] = new \Semitexa\Tenancy\CLI\TenantListCommand();
        }
        if (class_exists(\Semitexa\Tenancy\CLI\TenantRunCommand::class)) {
            $commands[] = new \Semitexa\Tenancy\CLI\TenantRunCommand();
        }

        $this->addCommands($commands);
    }
}

