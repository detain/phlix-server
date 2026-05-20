<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'phlix',
            'username' => 'phlix',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'pool_size' => 20,
            'timeout' => 5,
        ],
    ],
];
