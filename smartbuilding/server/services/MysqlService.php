<?php

namespace App\Services;

use PDO;
use Exception;

class MysqlService
{
    private ?PDO $primaryPdo = null;
    private ?PDO $replicaPdo = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get connection to primary MySQL (reads and writes, mainly writes).
     */
    private function getPrimaryConnection(): PDO
    {
        if ($this->primaryPdo === null) {
            $dsn = sprintf(
                "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                $this->config['primary']['host'],
                $this->config['primary']['port'],
                $this->config['database']
            );
            $this->primaryPdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return $this->primaryPdo;
    }

    /**
     * Get connection to replica MySQL (reads only).
     * If the replica fails, it falls back to primary.
     */
    private function getReplicaConnection(): PDO
    {
        if ($this->replicaPdo === null) {
            try {
                $dsn = sprintf(
                    "mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                    $this->config['replica']['host'],
                    $this->config['replica']['port'],
                    $this->config['database']
                );
                $this->replicaPdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 2, // Short timeout for failover
                ]);
            } catch (Exception $e) {
                // Fallback to Primary if Replica is down
                return $this->getPrimaryConnection();
            }
        }
        return $this->replicaPdo;
    }

    /**
     * Save a reading to the primary database.
     */
    public function saveReading(string $room, float $energy, bool $presence, bool $light): bool
    {
        $pdo = $this->getPrimaryConnection();
        $stmt = $pdo->prepare("
            INSERT INTO historico_leituras (sala, energia, presenca, luz)
            VALUES (:sala, :energia, :presenca, :luz)
        ");
        
        return $stmt->execute([
            'sala' => $room,
            'energia' => $energy,
            'presenca' => $presence ? 1 : 0,
            'luz' => $light ? 1 : 0,
        ]);
    }

    /**
     * Fetch the reading history of a specific room from the replica.
     */
    public function getRoomHistory(string $room, int $limit = 50): array
    {
        $pdo = $this->getReplicaConnection();
        $stmt = $pdo->prepare("
            SELECT id, sala, energia, presenca, luz, criado_em
            FROM historico_leituras
            WHERE sala = :sala
            ORDER BY criado_em DESC
            LIMIT :limit
        ");
        
        $stmt->bindValue(':sala', $room, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Get aggregate statistics per room (e.g. average energy consumption).
     */
    public function getRoomStats(string $room): array
    {
        $pdo = $this->getReplicaConnection();
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_leituras,
                AVG(energia) as media_energia,
                MAX(energia) as max_energia,
                SUM(presenca) as tempo_ocupado_smp
            FROM historico_leituras
            WHERE sala = :sala
        ");
        $stmt->execute(['sala' => $room]);
        return $stmt->fetch() ?: [];
    }
}
