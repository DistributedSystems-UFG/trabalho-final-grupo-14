<?php

namespace App\Services;

use Predis\Client;

class RedisService
{
    private ?Client $client = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'scheme' => 'tcp',
                'host'   => $this->config['host'],
                'port'   => $this->config['port'],
            ]);
        }
        return $this->client;
    }

    /**
     * Set the current state of a room with TTL.
     */
    public function setRoomState(string $roomId, array $state, int $ttl = 60): bool
    {
        $client = $this->getClient();
        $key = "sala:{$roomId}:estado";
        $data = json_encode($state);
        
        $client->setex($key, $ttl, $data);
        return true;
    }

    /**
     * Get the current state of a room.
     */
    public function getRoomState(string $roomId): ?array
    {
        $client = $this->getClient();
        $key = "sala:{$roomId}:estado";
        $data = $client->get($key);
        
        return $data ? json_decode($data, true) : null;
    }

    /**
     * Get all active rooms state.
     */
    public function getAllRoomsState(): array
    {
        $client = $this->getClient();
        $keys = $client->keys("sala:*:estado");
        $states = [];
        
        foreach ($keys as $key) {
            // Remove 'sala:' prefix and ':estado' suffix to get the room ID
            preg_match('/sala:(.*):estado/', $key, $matches);
            $roomId = $matches[1] ?? null;
            
            if ($roomId) {
                $data = $client->get($key);
                if ($data) {
                    $states[$roomId] = json_decode($data, true);
                }
            }
        }
        
        return $states;
    }

    /**
     * Acquire a distributed lock for a room using SETNX.
     */
    public function acquireLock(string $roomId, int $expireSeconds = 5): bool
    {
        $client = $this->getClient();
        $lockKey = "lock:sala:{$roomId}";
        
        // SET $lockKey 1 NX EX $expireSeconds
        $result = $client->set($lockKey, '1', 'ex', $expireSeconds, 'nx');
        return $result == 'OK' || $result === true;
    }

    /**
     * Release a distributed lock.
     */
    public function releaseLock(string $roomId): void
    {
        $client = $this->getClient();
        $lockKey = "lock:sala:{$roomId}";
        $client->del($lockKey);
    }
}
