<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Exception;

class RabbitService
{
    private ?AMQPStreamConnection $connection = null;
    private $channel = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    private function getConnection(): AMQPStreamConnection
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password']
            );
            $this->channel = $this->connection->channel();
        }
        return $this->connection;
    }

    private function getChannel()
    {
        $this->getConnection();
        return $this->channel;
    }

    /**
     * Publish a message to a specific exchange.
     */
    public function publish(string $exchange, string $routingKey, array $data): void
    {
        $channel = $this->getChannel();
        $payload = json_encode($data);
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        
        $channel->basic_publish($msg, $exchange, $routingKey);
    }

    /**
     * Publish a message directly to a queue (like 'alertas').
     */
    public function publishToQueue(string $queueName, array $data): void
    {
        $channel = $this->getChannel();
        
        // Ensure the queue exists
        $channel->queue_declare($queueName, false, true, false, false);
        
        $payload = json_encode($data);
        $msg = new AMQPMessage($payload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        
        $channel->basic_publish($msg, '', $queueName);
    }

    /**
     * Start consuming messages from a queue.
     * This is blocking, so it must be run in a Swoole process or background worker.
     */
    public function consume(string $queueName, callable $callback): void
    {
        $channel = $this->getChannel();
        
        // Ensure the queue exists
        $channel->queue_declare($queueName, false, true, false, false);
        
        // Fair dispatch
        $channel->basic_qos(null, 1, null);
        
        $channel->basic_consume(
            $queueName,
            '',
            false,
            false, // no_ack = false for manual acknowledgement
            false,
            false,
            function (AMQPMessage $msg) use ($callback) {
                $payload = json_decode($msg->body, true);
                
                // Call the user-defined callback
                $success = $callback($payload);
                
                if ($success) {
                    // Manual ACK: confirm message was processed successfully
                    $msg->ack();
                } else {
                    // NACK: requeue message if processing failed
                    $msg->nack(true);
                }
            }
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    public function close(): void
    {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
    }
}
