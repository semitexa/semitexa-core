<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Container\RequestScopedContainer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'test:handler', description: 'Test handler instantiation and property injection')]
class TestHandlerCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('test:handler')
            ->setDescription('Test handler instantiation and property injection')
            ->addArgument('handler', InputArgument::REQUIRED, 'Handler class name (FQN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $handlerClass = $input->getArgument('handler');

        $io->title('Testing Handler: ' . $handlerClass);

        try {
            // Test 1: Direct container get()
            $io->section('Test 1: Direct container->get()');
            $container = ContainerFactory::get();
            try {
                $handler1 = $container->get($handlerClass);
                $io->success('✅ get() succeeded');
                $this->inspectHandler($io, $handler1, $handlerClass);
            } catch (\Throwable $e) {
                $io->error('❌ get() failed: ' . $e->getMessage());
            }

            // Test 2: RequestScopedContainer get()
            $io->section('Test 2: RequestScopedContainer->get()');
            $requestScoped = ContainerFactory::getRequestScoped();
            try {
                $handler2 = $requestScoped->get($handlerClass);
                $io->success('✅ RequestScopedContainer->get() succeeded');
                $this->inspectHandler($io, $handler2, $handlerClass);
            } catch (\Throwable $e) {
                $io->error('❌ RequestScopedContainer->get() failed: ' . $e->getMessage());
            }

            // Test 3: Check if handler is registered
            $io->section('Test 3: Container has()');
            $hasDefinition = $container->has($handlerClass);
            $io->text('Has entry: ' . ($hasDefinition ? '✅ yes' : '❌ no'));

        } catch (\Throwable $e) {
            $io->error('Fatal error: ' . $e->getMessage());
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function inspectHandler(SymfonyStyle $io, object $handler, string $handlerClass): void
    {
        $reflection = new \ReflectionClass($handler);
        
        $io->text('Class: ' . get_class($handler));
        $io->text('Properties:');
        
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $name = $property->getName();
            $value = $property->getValue($handler);
            
            $attrs = [];
            if (!empty($property->getAttributes(InjectAsReadonly::class))) {
                $attrs[] = 'InjectAsReadonly';
            }
            if (!empty($property->getAttributes(InjectAsMutable::class))) {
                $attrs[] = 'InjectAsMutable';
            }
            if (!empty($property->getAttributes(InjectAsFactory::class))) {
                $attrs[] = 'InjectAsFactory';
            }
            $injectMark = $attrs !== [] ? ' [' . implode(', ', $attrs) . ']' : '';
            
            if ($value === null) {
                $io->text("  ❌ {$name}: NULL (not initialized){$injectMark}");
            } else {
                $io->text("  ✅ {$name}: " . get_class($value) . $injectMark);
            }
        }
    }
}

