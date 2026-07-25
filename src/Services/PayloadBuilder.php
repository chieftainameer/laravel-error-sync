<?php
// src/Services/PayloadBuilder.php

namespace NativePHP\ErrorSync\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;

class PayloadBuilder
{
    public function build(
        string $trigger,
        array $phpErrors,
        array $jsErrors,
        array $networkLog,
        array $userActions,
        array $consoleLogs,
        ?string $screenshot = null
    ): array {
        $lastError = $this->getLastError($phpErrors, $jsErrors);

        $payload = [
            'error' => $lastError['message'] ?? 'Manual capture',
            'errorType' => $lastError['type'] ?? 'unknown',
            'errorFile' => $lastError['file'] ?? null,
            'errorLine' => $lastError['line'] ?? null,
            'stackTrace' => $lastError['stack'] ?? null,
            'trigger' => $trigger,
            'capturedAt' => now()->toIso8601String(),
        ];

        // Add optional sections based on config
        if (config('error-sync.collect.php_errors', true)) {
            $payload['phpErrors'] = array_slice($phpErrors, -20);
        }

        if (config('error-sync.collect.js_errors', true)) {
            $payload['jsErrors'] = array_slice($jsErrors, -20);
        }

        if (config('error-sync.collect.request', true)) {
            $payload['url'] = request()->fullUrl();
            $payload['route'] = request()->path();
            $payload['routeName'] = request()->route()?->getName();
            $payload['method'] = request()->method();
            $payload['input'] = $this->sanitizeInput(request()->except(
                config('error-sync.sensitive_fields', ['password'])
            ));
        }

        if (config('error-sync.collect.session', true)) {
            $payload['session'] = $this->captureSession();
        }

        if (config('error-sync.collect.user_actions', true)) {
            $payload['userActions'] = array_slice($userActions, -30);
        }

        if (config('error-sync.collect.network_requests', true)) {
            $payload['networkRequests'] = array_slice($networkLog, -20);
        }

        if (config('error-sync.collect.console_logs', true)) {
            $payload['consoleLogs'] = $consoleLogs;
        }

        if (config('error-sync.collect.laravel_logs', true)) {
            $payload['laravelLogs'] = $this->getRecentLogs(40);
        }

        if (config('error-sync.collect.database_queries', true)) {
            $payload['recentQueries'] = $this->getRecentQueries();
        }

        $payload['app'] = [
            'name' => config('app.name'),
            'env' => app()->environment(),
            'debug' => config('app.debug'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ];

        $payload['device'] = [
            'userAgent' => request()->userAgent(),
            'ip' => request()->ip(),
        ];

        // Add screenshot if present
        if ($screenshot && config('error-sync.collect.screenshot', true)) {
            // Keep the data URI intact; cutting base64 makes the image unreadable.
            $payload['screenshot'] = $screenshot;
        }

        return $payload;
    }

    protected function getLastError(array $phpErrors, array $jsErrors): array
    {
        $all = array_merge(
            array_reverse($phpErrors),
            array_reverse($jsErrors)
        );
        return $all[0] ?? [];
    }

    protected function sanitizeInput(array $input): array
    {
        $sensitive = config('error-sync.sensitive_fields', []);

        return collect($input)
            ->map(function ($value, $key) use ($sensitive) {
                // Redact sensitive fields
                foreach ($sensitive as $field) {
                    if (stripos($key, $field) !== false) {
                        return '[REDACTED]';
                    }
                }
                // Truncate large strings
                if (is_string($value) && strlen($value) > 500) {
                    return substr($value, 0, 500) . '... [truncated]';
                }
                return $value;
            })
            ->toArray();
    }

    protected function captureSession(): array
    {
        try {
            $data = Session::all();
            // Remove CSRF token
            unset($data['_token'], $data['_previous']);
            return $data;
        } catch (\Throwable) {
            return ['error' => 'Could not read session'];
        }
    }

    protected function getRecentLogs(int $lines = 40): array
    {
        $path = storage_path('logs/laravel.log');

        if (!File::exists($path)) {
            return ['No log file found'];
        }

        try {
            return File::lines($path)->slice(-$lines)->values()->toArray();
        } catch (\Throwable) {
            return ['Could not read log file'];
        }
    }

    protected function getRecentQueries(): array
    {
        if (!app()->bound('db')) {
            return [];
        }

        try {
            $connection = app('db')->connection();
            $wasLogging = $connection->logging();

            if (!$wasLogging) {
                $connection->enableQueryLog();
            }

            $queries = $connection->getQueryLog();

            if (!$wasLogging) {
                $connection->disableQueryLog();
            }

            return array_map(function ($q) {
                return [
                    'sql' => $q['query'],
                    'time' => $q['time'] . 'ms',
                ];
            }, array_slice($queries, -10));
        } catch (\Throwable) {
            return [];
        }
    }
}
