<?php

declare(strict_types=1);

namespace Semitexa\Core\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Response;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validate all handler signatures, return types, and payload/resource bindings.
 */
#[AsCommand(name: 'semitexa:lint:handlers', description: 'Validate handler signatures, return types, and payload/resource bindings')]
final class LintHandlersCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Lint: Handlers');

        $handlerClasses = AttributeDiscovery::getDiscoveredPayloadHandlerClassNames();
        $errors = [];

        foreach ($handlerClasses as $handlerClass) {
            try {
                $ref = new \ReflectionClass($handlerClass);
            } catch (\Throwable $e) {
                $errors[] = "{$handlerClass}: Cannot reflect — {$e->getMessage()}";
                continue;
            }

            // Must implement TypedHandlerInterface
            if (!$ref->implementsInterface(TypedHandlerInterface::class)) {
                $errors[] = "{$handlerClass}: Does not implement TypedHandlerInterface.";
                continue;
            }

            // Must have handle() method
            if (!$ref->hasMethod('handle')) {
                $errors[] = "{$handlerClass}: Missing handle() method.";
                continue;
            }

            $method = $ref->getMethod('handle');

            // handle() must be public
            if (!$method->isPublic()) {
                $errors[] = "{$handlerClass}::handle() must be public.";
            }

            // Must have at least 2 params
            $params = $method->getParameters();
            if (count($params) < 2) {
                $errors[] = "{$handlerClass}::handle() must accept at least 2 parameters (payload, resource).";
                continue;
            }

            // Param 0: concrete class type
            $p0Type = $params[0]->getType();
            if (!$p0Type instanceof \ReflectionNamedType || $p0Type->isBuiltin()) {
                $errors[] = "{$handlerClass}::handle() parameter 0 must be a concrete class type.";
            }

            // Param 1: must implement ResourceInterface
            $p1Type = $params[1]->getType();
            if (!$p1Type instanceof \ReflectionNamedType || $p1Type->isBuiltin()) {
                $errors[] = "{$handlerClass}::handle() parameter 1 must be a concrete ResourceInterface type.";
            } elseif (!is_subclass_of($p1Type->getName(), ResourceInterface::class)
                && $p1Type->getName() !== ResourceInterface::class) {
                $errors[] = "{$handlerClass}::handle() parameter 1 type {$p1Type->getName()} must implement ResourceInterface.";
            }

            // Return type must not be Response
            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType && $returnType->getName() === Response::class) {
                $errors[] = "{$handlerClass}::handle() must return ResourceInterface, not Response.";
            }

            // #[AsPayloadHandler] validation
            $attrs = $ref->getAttributes(AsPayloadHandler::class);
            if ($attrs !== []) {
                $attr = $attrs[0]->newInstance();
                if (!class_exists($attr->payload)) {
                    $errors[] = "{$handlerClass}: Payload class {$attr->payload} does not exist.";
                }
                if (!class_exists($attr->resource)) {
                    $errors[] = "{$handlerClass}: Resource class {$attr->resource} does not exist.";
                }
            }
        }

        if ($errors === []) {
            $io->success(sprintf('All %d handlers are valid.', count($handlerClasses)));
            return self::SUCCESS;
        }

        foreach ($errors as $error) {
            $io->error($error);
        }
        $io->error(sprintf('%d error(s) found in %d handlers.', count($errors), count($handlerClasses)));
        return self::FAILURE;
    }
}
