<?php

namespace App\Handlers;

use Swoole\Http\Request;
use Swoole\Http\Response;
use App\Services\RedisService;
use App\Services\MysqlService;

class HttpHandler
{
    private RedisService $redisService;
    private MysqlService $mysqlService;

    public function __construct(RedisService $redisService, MysqlService $mysqlService)
    {
        $this->redisService = $redisService;
        $this->mysqlService = $mysqlService;
    }

    public function onRequest(Request $request, Response $response): void
    {
        // Setup CORS headers
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        $response->header('Content-Type', 'application/json');

        if ($request->server['request_method'] === 'OPTIONS') {
            $response->status(200);
            $response->end();
            return;
        }

        $uri = $request->server['request_uri'];

        try {
            // GET /api/salas
            if ($uri === '/api/salas' || $uri === '/api/salas/') {
                $states = $this->redisService->getAllRoomsState();
                $response->end(json_encode([
                    'success' => true,
                    'data' => $states
                ]));
                return;
            }

            // GET /api/salas/{sala}/historico
            if (preg_match('#^/api/salas/([^/]+)/historico$#', $uri, $matches)) {
                $room = urldecode($matches[1]);
                $limit = isset($request->get['limit']) ? (int)$request->get['limit'] : 50;
                
                $history = $this->mysqlService->getRoomHistory($room, $limit);
                $stats = $this->mysqlService->getRoomStats($room);

                $response->end(json_encode([
                    'success' => true,
                    'room' => $room,
                    'stats' => $stats,
                    'history' => $history
                ]));
                return;
            }

            // 404 Route not found
            $response->status(404);
            $response->end(json_encode([
                'success' => false,
                'message' => "Route not found: {$uri}"
            ]));

        } catch (\Throwable $e) {
            $response->status(500);
            $response->end(json_encode([
                'success' => false,
                'message' => 'Internal Server Error',
                'error' => $e->getMessage()
            ]));
        }
    }
}
