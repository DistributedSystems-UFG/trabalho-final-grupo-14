<?php

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\Process;
use App\Services\RedisService;
use App\Services\MysqlService;
use App\Services\RabbitService;
use App\Handlers\WsHandler;
use App\Handlers\HttpHandler;

// Enable Swoole coroutine runtime hooks for blocking calls (sockets, pdo, etc.)
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

// Instantiate the Main Server (HTTP + WebSocket on port 8000)
$server = new Server("0.0.0.0", 8000);

// Add a secondary port listener for 9502 to match the Docker mapping specifically for WS
$wsPort = $server->listen("0.0.0.0", 9502, SWOOLE_SOCK_TCP);
$wsPort->set([
    'open_websocket_protocol' => true,
]);

// Server settings
$server->set([
    'worker_num' => 4,
    'enable_static_handler' => true,
    'document_root' => __DIR__ . '/../dashboard', // serve frontend if requested directly
]);

// Shared services (initialized in workerStart)
$redisService = null;
$mysqlService = null;
$wsHandler = null;
$httpHandler = null;

$server->on('Start', function (Server $server) {
    echo "Smart Building IoT Server started at http://127.0.0.1:8000\n";
    echo "WebSocket listeners active on ports 8000 and 9502\n";
});

$server->on('WorkerStart', function (Server $server, int $workerId) use ($config, &$redisService, &$mysqlService, &$wsHandler, &$httpHandler) {
    // Instantiate services per worker to ensure thread safety / isolated connections
    $redisService = new RedisService($config['redis']);
    $mysqlService = new MysqlService($config['mysql']);
    
    $wsHandler = new WsHandler($server);
    $httpHandler = new HttpHandler($redisService, $mysqlService);
    
    echo "Worker #{$workerId} started.\n";
});

// Route HTTP requests
$server->on('Request', function (Request $request, Response $response) use (&$httpHandler) {
    if ($httpHandler) {
        $httpHandler->onRequest($request, $response);
    } else {
        $response->status(500);
        $response->end(json_encode(['error' => 'HTTP Handler not ready']));
    }
});

// Route WebSocket events (Primary Port)
$server->on('Open', function (Server $server, Request $request) use (&$wsHandler) {
    $wsHandler->onOpen($server, $request);
});

$server->on('Message', function (Server $server, Frame $frame) use (&$wsHandler) {
    $wsHandler->onMessage($server, $frame);
});

$server->on('Close', function (Server $server, int $fd) use (&$wsHandler) {
    $wsHandler->onClose($server, $fd);
});

// Route WebSocket events (Secondary Port Listener 9502)
$wsPort->on('Open', function (Server $server, Request $request) use (&$wsHandler) {
    $wsHandler->onOpen($server, $request);
});

$wsPort->on('Message', function (Server $server, Frame $frame) use (&$wsHandler) {
    $wsHandler->onMessage($server, $frame);
});

$wsPort->on('Close', function (Server $server, int $fd) use (&$wsHandler) {
    $wsHandler->onClose($server, $fd);
});

// IPC Handler: when a background process sends a message to be broadcasted
$server->on('PipeMessage', function (Server $server, int $srcWorkerId, $message) use (&$wsHandler) {
    $data = json_decode($message, true);
    if ($data && isset($data['ipc_type']) && $data['ipc_type'] === 'broadcast' && $wsHandler) {
        $wsHandler->broadcast($data['payload']);
    }
});

// Process 1: Background Consumer for Sensor Readings Queue (swoole_sensor_queue)
$sensorConsumerProcess = new Process(function (Process $process) use ($server, $config) {
    echo "Background Sensor Consumer Process started.\n";
    
    // Connect services inside the process
    $redis = new RedisService($config['redis']);
    $rabbit = new RabbitService($config['rabbitmq']);
    
    // Wait until services are fully ready
    sleep(3);
    
    try {
        $rabbit->consume($config['rabbitmq']['sensor_queue'], function (array $payload) use ($server, $redis) {
            $room = $payload['sala'] ?? null;
            if (!$room) {
                return true; // Malformed msg, discard (ACK)
            }
            
            echo "[Sensor Process] Processing reading for room {$room}\n";
            
            // Acquire Redis lock for the room to prevent race conditions
            $lockAcquired = $redis->acquireLock($room, 5);
            if (!$lockAcquired) {
                echo "[Sensor Process] Lock for room {$room} already held. Retrying...\n";
                return false; // Requeue and try later
            }
            
            try {
                // Update Redis cache with state and set 60s TTL
                $redis->setRoomState($room, [
                    'sala' => $room,
                    'energia' => (float)($payload['energia'] ?? 0.0),
                    'presenca' => (bool)($payload['presenca'] ?? false),
                    'luz' => (bool)($payload['luz'] ?? false),
                    'atualizado_em' => time()
                ], 60);
            } finally {
                // Release the lock
                $redis->releaseLock($room);
            }
            
            // Send IPC message to Worker 0 to broadcast via WebSocket
            $ipcMessage = json_encode([
                'ipc_type' => 'broadcast',
                'payload' => [
                    'type' => 'sensor_update',
                    'sala' => $room,
                    'data' => $payload,
                    'timestamp' => time()
                ]
            ]);
            $server->sendMessage($ipcMessage, 0);
            
            return true; // Successfully processed, send ACK
        });
    } catch (\Throwable $e) {
        echo "[Sensor Process] Error: " . $e->getMessage() . "\n";
        sleep(2);
    }
}, false, 2, true);

// Process 2: Background Consumer for Alerts Queue (alertas)
$alertConsumerProcess = new Process(function (Process $process) use ($server, $config) {
    echo "Background Alert Consumer Process started.\n";
    
    $rabbit = new RabbitService($config['rabbitmq']);
    
    // Wait until services are fully ready
    sleep(3);
    
    try {
        $rabbit->consume($config['rabbitmq']['alert_queue'], function (array $payload) use ($server) {
            $room = $payload['sala'] ?? 'Unknown';
            echo "[Alert Process] New alert detected for room {$room}!\n";
            
            // Send IPC message to Worker 0 to broadcast alert via WebSocket
            $ipcMessage = json_encode([
                'ipc_type' => 'broadcast',
                'payload' => [
                    'type' => 'anomaly_alert',
                    'sala' => $room,
                    'alert_type' => $payload['tipo'] ?? 'generic',
                    'message' => $payload['mensagem'] ?? 'Alerta sem mensagem',
                    'timestamp' => $payload['timestamp'] ?? time()
                ]
            ]);
            $server->sendMessage($ipcMessage, 0);
            
            return true; // ACK the alert message
        });
    } catch (\Throwable $e) {
        echo "[Alert Process] Error: " . $e->getMessage() . "\n";
        sleep(2);
    }
}, false, 2, true);

// Add the background processes to the Swoole Server
$server->addProcess($sensorConsumerProcess);
$server->addProcess($alertConsumerProcess);

$server->start();
