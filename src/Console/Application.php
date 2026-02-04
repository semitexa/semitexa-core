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

class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('Semitexa', '1.0.0');
        
        $commands = [
            new InitCommand(),
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

        // Add ORM commands if available
        if (class_exists(\Semitexa\Orm\Console\Command\EntityGenerateCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\EntityGenerateCommand();
        }
        if (class_exists(\Semitexa\Orm\Console\Command\DomainGenerateCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\DomainGenerateCommand();
        }
        if (class_exists(\Semitexa\Orm\Console\Command\MigrateCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\MigrateCommand();
        }
        if (class_exists(\Semitexa\Orm\Console\Command\DatabaseBuildCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\DatabaseBuildCommand();
        }
        if (class_exists(\Semitexa\Orm\Console\Command\BlockchainConsumeCommand::class)) {
            $commands[] = new \Semitexa\Orm\Console\Command\BlockchainConsumeCommand();
        }

        $this->addCommands($commands);
    }
}

