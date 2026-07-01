<?php

require_once __DIR__ . '/../server/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Get room ID from arguments
$roomId = $argv[1] ?? null;
if (!$roomId) {
    echo "Usage: php sensor.php <room_id> [interval_seconds]\n";
    exit(1);
}

$interval = isset($argv[2]) ? (int)$argv[2] : 3;

// Load RabbitMQ configuration from environment or fallback
$host = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$port = (int)(getenv('RABBITMQ_PORT') ?: 5672);
$user = getenv('RABBITMQ_USER') ?: 'guest';
$password = getenv('RABBITMQ_PASS') ?: 'guest';
$exchange = 'sensor_data_exchange';

echo "Sensor for room {$roomId} starting, publishing to rabbitmq://{$user}:***@{$host}:{$port}\n";

try {
    $connection = new AMQPStreamConnection($host, $port, $user, $password);
    $channel = $connection->channel();
    
    // Ensure the exchange exists
    $channel->exchange_declare($exchange, 'fanout', false, true, false);
    
    // Initial room state simulation variables
    $presence = (rand(0, 100) > 30); // 70% chance of starting occupied
    $light = $presence; // Light usually matches presence
    
    while (true) {
        // Tumble states with realistic probabilities
        if (rand(0, 100) < 15) {
            // 15% chance to toggle presence
            $presence = !$presence;
        }
        
        // 10% chance to have an anomaly: room empty but lights on!
        if (!$presence && rand(0, 100) < 15) {
            $light = true;
        } else {
            $light = $presence;
        }
        
        // Calculate realistic energy consumption
        if ($presence) {
            // Occupied room uses more energy
            $energy = round(1.5 + (rand(0, 300) / 100), 2); // 1.5 to 4.5 kW
        } elseif ($light) {
            // Empty but lights on uses medium energy
            $energy = round(0.4 + (rand(0, 60) / 100), 2); // 0.4 to 1.0 kW
        } else {
            // Empty and dark uses standby energy
            $energy = round(0.02 + (rand(0, 10) / 100), 2); // 0.02 to 0.12 kW
        }
        
        // For testing high consumption anomaly
        if ($presence && rand(0, 100) < 5) {
            $energy = round(5.5 + (rand(0, 200) / 100), 2); // 5.5 to 7.5 kW (anomaly!)
        }

        $payload = [
            'sala' => $roomId,
            'energia' => $energy,
            'presenca' => $presence,
            'luz' => $light,
            'timestamp' => time()
        ];
        
        $msgJson = json_encode($payload);
        $msg = new AMQPMessage($msgJson, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        
        $channel->basic_publish($msg, $exchange);
        
        echo "[Room {$roomId}] Published reading: Energy={$energy}kW, Presence=" . ($presence ? 'YES' : 'NO') . ", Light=" . ($light ? 'ON' : 'OFF') . "\n";
        
        sleep($interval);
    }
    
    $channel->close();
    $connection->close();
    
} catch (Exception $e) {
    echo "Sensor Error: " . $e->getMessage() . "\n";
    exit(1);
}
