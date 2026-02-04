<?php

declare(strict_types=1);

namespace Semitexa\Core\Queue\Transport;

use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;
use Semitexa\Core\Environment;

/**
 * Factory for creating RabbitMQ transports
 */
class RabbitMqTransportFactory implements QueueTransportFactoryInterface
{
    /**
     * Create a RabbitMQ transport
     */
    public function create(): QueueTransportInterface
    {
        // Try to get RabbitMQ host (respect both generic and blockchain-specific env vars)
        $host = Environment::getEnvValue('RABBITMQ_HOST') 
             ?? Environment::getEnvValue('BLOCKCHAIN_RABBITMQ_HOST', '127.0.0.1');
             
        $port = (int) (Environment::getEnvValue('RABBITMQ_PORT') 
                    ?? Environment::getEnvValue('BLOCKCHAIN_RABBITMQ_PORT', '5672'));
                    
        $user = Environment::getEnvValue('RABBITMQ_USER') 
             ?? Environment::getEnvValue('BLOCKCHAIN_RABBITMQ_USER', 'guest');
             
        $pass = Environment::getEnvValue('RABBITMQ_PASSWORD') 
             ?? Environment::getEnvValue('BLOCKCHAIN_RABBITMQ_PASSWORD', 'guest');
             
        $vhost = Environment::getEnvValue('RABBITMQ_VHOST') 
              ?? Environment::getEnvValue('BLOCKCHAIN_RABBITMQ_VHOST', '/production');

        return new RabbitMqTransport(
            host: $host,
            port: $port,
            user: $user,
            password: $pass,
            vhost: $vhost,
        );
    }
}
