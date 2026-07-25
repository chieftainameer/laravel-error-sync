<?php
// src/Services/ErrorCaptureService.php

namespace NativePHP\ErrorSync\Services;

use Illuminate\Support\Facades\Storage;
use NativePHP\ErrorSync\Services\LocalStorageService;

class ErrorCaptureService
{
    protected array $phpErrors = [];
    protected array $jsErrors = [];
    protected array $networkLog = [];
    protected array $userActions = [];
    protected array $consoleLogs = [];
    protected bool $isCapturing = false;
    // Add property:
    protected ?string $screenshot = null;
    protected ?string $screenshotDiagnostic = null;

    public function __construct(
        protected RelayClient $relay,
        protected PayloadBuilder $builder,
        protected ?LocalStorageService $localStorage = null,
    ) {}

    public function boot(): void
    {
        if (!config('error-sync.triggers.auto', true)) {
            return;
        }

        set_error_handler([$this, 'handlePhpError']);
        set_exception_handler([$this, 'handlePhpException']);
        register_shutdown_function([$this, 'handleFatalError']);
    }

    public function handlePhpError(int $severity, string $message, string $file, int $line): bool
    {
        if (error_reporting() === 0) {
            return false;
        }

        $this->phpErrors[] = [
            'type' => $this->severityToString($severity),
            'message' => $message,
            'file' => $this->relativePath($file),
            'line' => $line,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->trimBuffer($this->phpErrors);

        if (in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            $this->captureAndSend('php_fatal');
        }

        return false;
    }

    public function handlePhpException(\Throwable $e): void
    {
        $this->phpErrors[] = [
            'type' => 'exception',
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $this->relativePath($e->getFile()),
            'line' => $e->getLine(),
            'stack' => $e->getTraceAsString(),
            'timestamp' => now()->toIso8601String(),
        ];

        $this->trimBuffer($this->phpErrors);
        $this->captureAndSend('unhandled_exception');
    }

    public function handleFatalError(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            return;
        }

        $this->phpErrors[] = [
            'type' => 'fatal',
            'message' => $error['message'],
            'file' => $this->relativePath($error['file']),
            'line' => $error['line'],
            'timestamp' => now()->toIso8601String(),
        ];

        $this->captureAndSend('php_fatal_shutdown');
    }

    public function captureJsError(array $jsError): void
    {
        $this->jsErrors[] = [
            'type' => 'javascript',
            'message' => $jsError['message'] ?? 'Unknown JS error',
            'source' => $jsError['source'] ?? 'unknown',
            'line' => $jsError['lineno'] ?? 0,
            'col' => $jsError['colno'] ?? 0,
            'stack' => $jsError['stack'] ?? '',
            'timestamp' => now()->toIso8601String(),
        ];
        $this->trimBuffer($this->jsErrors);
    }

    public function logNetwork(array $data): void
    {
        $this->networkLog[] = [
            'method' => $data['method'] ?? 'GET',
            'url' => $data['url'] ?? '',
            'status' => $data['status'] ?? null,
            'duration' => ($data['duration'] ?? 0) . 'ms',
            'error' => $data['error'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ];
        $this->trimBuffer($this->networkLog);
    }

    public function logUserAction(string $action, array $context = []): void
    {
        $this->userActions[] = [
            'action' => $action,
            'context' => $context,
            'route' => request()->path(),
            'timestamp' => now()->toIso8601String(),
        ];
        $this->trimBuffer($this->userActions);
    }

    public function logConsole(string $level, string $message): void
    {
        $this->consoleLogs[] = [
            'level' => $level,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
        $this->trimBuffer($this->consoleLogs);
    }

    /**
     * Set the screenshot data (base64) for the next capture.
     */
    public function setScreenshot(?string $screenshot): void
    {
        $this->screenshot = $screenshot;
    }

    public function setScreenshotDiagnostic(?string $diagnostic): void
    {
        $this->screenshotDiagnostic = $diagnostic;
    }

    // Update captureAndSend to pass screenshot to builder:
    public function captureAndSend(string $trigger = 'manual'): array
    {
        if ($this->isCapturing) {
            return ['success' => false, 'error' => 'Already capturing'];
        }

        $this->isCapturing = true;

        try {
            $payload = $this->builder->build(
                trigger: $trigger,
                phpErrors: $this->phpErrors,
                jsErrors: $this->jsErrors,
                networkLog: $this->networkLog,
                userActions: $this->userActions,
                consoleLogs: $this->consoleLogs,
                screenshot: $this->screenshot,
            );

            // Custom PayloadBuilder subclasses may omit the optional screenshot.
            // The capture service owns transport, so enforce it on the final
            // payload after the customizable builder has returned.
            if ($this->screenshot !== null && config('error-sync.collect.screenshot', true)) {
                $payload['screenshot'] = $this->screenshot;
            }

            if ($this->screenshotDiagnostic !== null) {
                $payload['screenshotDiagnostic'] = $this->screenshotDiagnostic;
            }

            // === ALWAYS SAVE LOCALLY ===
            $filename = null;
            if ($this->localStorage) {
                $filename = $this->localStorage->save($payload, $this->screenshot);
            }

            // === TRY TO SEND TO RELAY ===
            $sent = $this->relay->send($payload);

            // Clear screenshot after use
            $this->screenshot = null;
            $this->screenshotDiagnostic = null;

            return [
                'success' => $sent,
                'trigger' => $trigger,
                'errors_captured' => count($this->phpErrors) + count($this->jsErrors),
                'saved_locally' => $filename !== null,
                'local_file' => $filename,
            ];
        } catch (\Throwable $e) {
            $this->screenshot = null;
            $this->screenshotDiagnostic = null;
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            $this->isCapturing = false;
        }
    }

    protected function saveLocally(array $payload): void
    {
        if (!config('error-sync.local_backup.enabled', true)) {
            return;
        }

        $disk = config('error-sync.local_backup.disk', 'local');
        $path = config('error-sync.local_backup.path', 'error-sync');

        Storage::disk($disk)->put(
            $path . '/' . now()->format('Ymd_His') . '_' . ($payload['trigger'] ?? 'unknown') . '.json',
            json_encode($payload, JSON_PRETTY_PRINT)
        );
    }

    protected function severityToString(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_NOTICE => 'E_NOTICE',
            E_DEPRECATED => 'E_DEPRECATED',
            default => 'UNKNOWN',
        };
    }

    protected function relativePath(string $path): string
    {
        if (str_starts_with($path, base_path())) {
            return substr($path, strlen(base_path()) + 1);
        }
        return $path;
    }

    protected function trimBuffer(array &$buffer): void
    {
        $limit = config('error-sync.buffer_size', 200);
        while (count($buffer) > $limit) {
            array_shift($buffer);
        }
    }

    public function getBufferStats(): array
    {
        return [
            'php_errors' => count($this->phpErrors),
            'js_errors' => count($this->jsErrors),
            'network_requests' => count($this->networkLog),
            'user_actions' => count($this->userActions),
            'console_logs' => count($this->consoleLogs),
        ];
    }

    /**
     * Capture a Throwable (works with DivisionByZeroError, TypeError, etc.)
     */
    public function captureException(\Throwable $e): void
    {
        $this->phpErrors[] = [
            'type' => 'exception',
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $this->relativePath($e->getFile()),
            'line' => $e->getLine(),
            'stack' => $e->getTraceAsString(),
            'timestamp' => now()->toIso8601String(),
        ];

        $this->trimBuffer($this->phpErrors);

        // Auto-send
        $this->captureAndSend('auto_exception');
    }

    /**
     * Capture an error message from logs
     */
    public function captureMessage(string $message, string $level = 'error'): void
    {
        $this->phpErrors[] = [
            'type' => 'log_' . $level,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];

        $this->trimBuffer($this->phpErrors);

        // Auto-send for errors
        if ($level === 'error') {
            $this->captureAndSend('auto_log_error');
        }
    }
}
