<?php

namespace App\Handlers;

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

class WsHandler
{
    private Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function onOpen(Server $server, Request $request): void
    {
        echo "WebSocket client connected: FD {$request->fd}\n";
        
        // Send initial connection confirmation
        $server->push($request->fd, json_encode([
            'type' => 'connection_established',
            'fd' => $request->fd,
            'timestamp' => time()
        ]));
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        echo "Received message from FD {$frame->fd}: {$frame->data}\n";
        
        // Handle ping/pong or client requests
        $data = json_decode($frame->data, true);
        if (isset($data['type']) && $data['type'] === 'ping') {
            $server->push($frame->fd, json_encode(['type' => 'pong', 'timestamp' => time()]));
        }
    }

    public function onClose(Server $server, int $fd): void
    {
        echo "WebSocket client disconnected: FD {$fd}\n";
    }

    /**
     * Broadcast a message to all connected and established clients.
     */
    public function broadcast(array $payload): void
    {
        $message = json_encode($payload);
        foreach ($this->server->connections as $fd) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $message);
            }
        }
    }
}
