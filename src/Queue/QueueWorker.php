<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue;

use Semitexa\Core\Contract\AsyncResultDeliveryInterface;
use Semitexa\Core\Queue\Message\QueuedEventListenerMessage;
use Semitexa\Core\Queue\Message\QueuedHandlerMessage;
use Semitexa\Core\Support\DtoSerializer;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Util\ProjectRoot;

class QueueWorker
{
    private string $statsFile;
    private ?string $currentTransport = null;
    private ?string $currentQueue = null;
    private ?\Symfony\Component\Console\Output\OutputInterface $output = null;

    public function setOutput(?\Symfony\Component\Console\Output\OutputInterface $output): void
    {
        $this->output = $output;
    }

    private function log(string $message, string $level = 'info'): void
    {
        if ($this->output) {
            $tag = match ($level) {
                'error' => 'error',
                'warning' => 'comment',
                'success' => 'info', // Using info tag for success lines in console to appear green
                default => 'info',
            };
            $this->output->writeln("<{$tag}>{$message}</{$tag}>");
        } else {
            echo "{$message}\n";
        }
    }

    public function __construct()
    {
        $this->statsFile = ProjectRoot::get() . '/var/queue-stats.json';
        @mkdir(dirname($this->statsFile), 0777, true);
        
        // Initialize stats if file doesn't exist
        if (!file_exists($this->statsFile)) {
            file_put_contents($this->statsFile, json_encode([
                'processed' => 0,
                'failed' => 0,
                'start_time' => time(),
            ]));
        }
    }

    public function run(?string $transportName, ?string $queueName = null): void
    {
        $this->currentTransport = $transportName ?: QueueConfig::defaultTransport();
        $this->currentQueue = $queueName ?: QueueConfig::defaultQueueName('default');

        $transport = QueueTransportRegistry::create($this->currentTransport);

        $this->log("👷  Queue worker started (transport={$this->currentTransport}, queue={$this->currentQueue})");

        $transport->consume($this->currentQueue, function (string $payload): void {
            $this->processPayload($payload);
        });
    }

    public function processPayload(string $payload): void
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->log("❌ Failed to decode queued message: {$e->getMessage()}", 'error');
            $this->updateStats('failed');
            return;
        }

        $type = $data['type'] ?? 'handler';
        if ($type === QueuedEventListenerMessage::TYPE) {
            $this->processEventPayload($payload);
        } else {
            $this->processHandlerPayload($payload);
        }
    }

    private function processEventPayload(string $payload): void
    {
        try {
            $message = QueuedEventListenerMessage::fromJson($payload);
        } catch (\Throwable $e) {
            $this->log("❌ Failed to decode event message: {$e->getMessage()}", 'error');
            $this->updateStats('failed');
            return;
        }

        if (!class_exists($message->listenerClass)) {
            $this->log("⚠️  Event listener {$message->listenerClass} not found", 'warning');
            $this->updateStats('failed');
            return;
        }

        try {
            $event = $this->hydrateDto($message->eventClass, $message->eventPayload);
            $container = ContainerFactory::get();
            $listener = $container->get($message->listenerClass);
            if (!method_exists($listener, 'handle')) {
                $this->log("⚠️  Listener {$message->listenerClass} has no handle() method", 'warning');
                $this->updateStats('failed');
                return;
            }
        } catch (\Throwable $e) {
            $this->log("❌ Error preparing event listener: {$e->getMessage()}", 'error');
            $this->updateStats('failed');
            return;
        }

        try {
            $listener->handle($event);
            $this->log("✅ Async event listener executed: {$message->listenerClass}", 'success');
            $this->updateStats('processed');
        } catch (\Throwable $e) {
            $this->log("❌ Error executing event listener: {$e->getMessage()}", 'error');
            $this->updateStats('failed');
        }
    }

    private function processHandlerPayload(string $payload): void
    {
        try {
            $message = QueuedHandlerMessage::fromJson($payload);
        } catch (\Throwable $e) {
            $this->log("❌ Failed to decode handler message: {$e->getMessage()}", 'error');
            $this->updateStats('failed');
            return;
        }

        $handlerClass = $message->handlerClass;
        if (!class_exists($handlerClass)) {
            $this->log("⚠️  Handler {$handlerClass} not found", 'warning');
            $this->updateStats('failed');
            return;
        }

        try {
            $request = $this->hydrateDto($message->requestClass, $message->requestPayload);
            $response = $this->hydrateDto($message->responseClass, $message->responsePayload);

            $container = ContainerFactory::get();
            $handler = $container->get($handlerClass);
            if (!method_exists($handler, 'handle')) {
                $this->log("⚠️  Handler {$handlerClass} has no handle() method", 'warning');
                $this->updateStats('failed');
                return;
            }
        } catch (\Throwable $e) {
            $this->log("❌ Error processing payload: {$e->getMessage()}", 'error');
            $this->updateStats('failed');
            return;
        }

        try {
            $handler->handle($request, $response);
            $this->deliverAsyncResult($message->sessionId, $response, $handlerClass);
            $this->log("✅ Async handler executed: {$handlerClass}", 'success');
            $this->updateStats('processed');
        } catch (\Throwable $e) {
            $this->log("❌ Error executing handler: {$e->getMessage()}", 'error');
            
            if ($message->attempts < $message->maxRetries) {
                $message->attempts++;
                $delay = $message->retryDelay;
                $this->log("ℹ️  Retrying handler ({$message->attempts}/{$message->maxRetries}) in {$delay}s...", 'warning');
                
                if ($delay > 0) {
                    sleep($delay);
                }
                
                if ($this->currentTransport && $this->currentQueue) {
                    $transport = QueueTransportRegistry::create($this->currentTransport);
                    $transport->publish($this->currentQueue, $message->toJson());
                }
            } else {
                $this->log("💀 Max retries reached or no retries configured. Moving to DLQ.", 'error');
                $this->moveToDeadLetterQueue($message, $e->getMessage());
                $this->updateStats('failed');
            }
        }
    }

    private function moveToDeadLetterQueue(QueuedHandlerMessage $message, string $error): void
    {
        try {
            $transport = QueueTransportRegistry::create(QueueConfig::defaultTransport());
            $dlqName = QueueConfig::defaultQueueName($message->requestClass) . '.failed';
            $data = $message->jsonSerialize();
            $data['error'] = $error;
            $data['failed_at'] = date(DATE_ATOM);
            $transport->publish($dlqName, json_encode($data));
            $this->log("📥 Message moved to DLQ: {$dlqName}", 'warning');
        } catch (\Throwable $dlqError) {
            $this->log("❌ Failed to move message to DLQ: {$dlqError->getMessage()}", 'error');
        }
    }

    private function deliverAsyncResult(string $sessionId, object $responseDto, string $handlerClass = ''): void
    {
        if ($sessionId === '') {
            return;
        }
        try {
            $container = ContainerFactory::get();
            if (!$container->has(AsyncResultDeliveryInterface::class)) {
                return;
            }
            $delivery = $container->get(AsyncResultDeliveryInterface::class);
            if ($delivery instanceof AsyncResultDeliveryInterface) {
                $delivery->deliver($sessionId, $responseDto, $handlerClass);
            }
        } catch (\Throwable) {
            // Optional: no delivery implementation bound or delivery failed
        }
    }

    private function updateStats(string $type): void
    {
        $stats = json_decode(file_get_contents($this->statsFile), true) ?: [
            'processed' => 0,
            'failed' => 0,
            'start_time' => time(),
        ];
        $stats[$type] = ($stats[$type] ?? 0) + 1;
        file_put_contents($this->statsFile, json_encode($stats));
    }
    

    private function hydrateDto(string $class, array $payload): object
    {
        $dto = class_exists($class) ? new $class() : new \stdClass();

        return DtoSerializer::hydrate($dto, $payload);
    }
}

