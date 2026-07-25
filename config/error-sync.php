<?php
// config/error-sync.php

return [
    'relay_url' => env('ERROR_SYNC_RELAY_URL', 'http://192.168.1.100:9999'),
    'environments' => ['local', 'development'],
    'force_enable' => env('ERROR_SYNC_FORCE', false),
    'timeout' => 3,
    'triggers' => [
        'auto' => true,
        'shake' => true,
        'gesture' => true,
        'keyboard' => true,
        'button' => true,
    ],
    'collect' => [
        'php_errors' => true,
        'js_errors' => true,
        'stack_trace' => true,
        'request' => true,
        'session' => true,
        'user_actions' => true,
        'network_requests' => true,
        'console_logs' => true,
        'laravel_logs' => true,
        'database_queries' => true,
        'cache_state' => false,
        'screenshot' => true,
    ],
    'buffer_size' => 200,
    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'secret',
        'credit_card',
    ],
    'local_backup' => [
        'enabled' => true,
        'disk' => 'local',
        'path' => 'error-sync',
        'retain_days' => 7,
    ],
    'relay_server' => [
        'port' => env('ERROR_SYNC_RELAY_PORT', 9999),
        'host' => '0.0.0.0',
        'output_dir' => env('HOME') . '/agent-errors',
        'auto_start' => env('ERROR_SYNC_AUTO_START', false),
        'auto_stop' => env('ERROR_SYNC_AUTO_STOP', true),
    ],
];
