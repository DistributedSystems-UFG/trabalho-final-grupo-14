<?php

return [
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('REDIS_PORT') ?: 6379),
    ],
    'mysql' => [
        'primary' => [
            'host' => getenv('MYSQL_PRIMARY_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('MYSQL_PRIMARY_PORT') ?: 3306),
        ],
        'replica' => [
            'host' => getenv('MYSQL_REPLICA_HOST') ?: '127.0.0.1',
            'port' => (int)(getenv('MYSQL_REPLICA_PORT') ?: 3307),
        ],
        'database' => getenv('MYSQL_DB') ?: 'smartbuilding',
        'username' => getenv('MYSQL_USER') ?: 'sb_user',
        'password' => getenv('MYSQL_PASSWORD') ?: 'sb_password',
    ],
    'rabbitmq' => [
        'host' => getenv('RABBITMQ_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('RABBITMQ_PORT') ?: 5672),
        'user' => getenv('RABBITMQ_USER') ?: 'guest',
        'password' => getenv('RABBITMQ_PASS') ?: 'guest',
        'sensor_queue' => 'swoole_sensor_queue',
        'alert_queue' => 'alertas',
    ]
];
