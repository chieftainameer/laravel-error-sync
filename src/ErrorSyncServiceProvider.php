<?php
// src/ErrorSyncServiceProvider.php

namespace NativePHP\ErrorSync;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use NativePHP\ErrorSync\Services\ErrorCaptureService;
use NativePHP\ErrorSync\Services\LocalStorageService;
use NativePHP\ErrorSync\Services\RelayClient;
use NativePHP\ErrorSync\Services\PayloadBuilder;
use NativePHP\ErrorSync\Commands\InstallCommand;
use NativePHP\ErrorSync\Commands\RelayServerCommand;
use NativePHP\ErrorSync\Commands\StatusCommand;
use NativePHP\ErrorSync\Commands\ListErrorsCommand;
use NativePHP\ErrorSync\Commands\WatchErrorsCommand;
use NativePHP\ErrorSync\Commands\SyncCommand;
use NativePHP\ErrorSync\Listeners\StartRelayServer;

class ErrorSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/error-sync.php',
            'error-sync'
        );

        $this->app->singleton(RelayClient::class, function ($app) {
            return new RelayClient(
                config('error-sync.relay_url'),
                config('error-sync.timeout', 3)
            );
        });

        $this->app->singleton(PayloadBuilder::class);

        $this->app->singleton(ErrorCaptureService::class, function ($app) {
            return new ErrorCaptureService(
                $app->make(RelayClient::class),
                $app->make(PayloadBuilder::class),
                $app->make(LocalStorageService::class),
            );
        });

        $this->app->singleton(LocalStorageService::class);

        $this->app->bind('error-sync', ErrorCaptureService::class);
    }

    public function boot(): void
    {

        // Publishing
        $this->publishes([
            __DIR__ . '/../config/error-sync.php' => config_path('error-sync.php'),
        ], 'error-sync-config');

        $this->publishes([
            __DIR__ . '/../resources/js' => public_path('vendor/error-sync'),
        ], 'error-sync-assets');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/error-sync'),
        ], 'error-sync-views');

        $this->publishes([
            __DIR__ . '/../relay-server' => base_path('error-relay'),
        ], 'error-sync-relay');

        if (!$this->isEnabled()) {
            return;
        }

        // Views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'error-sync');

        // Middleware
        Route::middlewareGroup('error-sync', [
            \NativePHP\ErrorSync\Middleware\DevOnlyMiddleware::class,
        ]);

        // Routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/error-sync.php');

        // Auto-start relay server if enabled
        if (config('error-sync.relay_server.auto_start', false)) {
            StartRelayServer::start();
        }

        // Register shutdown to stop relay
        if (config('error-sync.relay_server.auto_stop', true)) {
            register_shutdown_function(function () {
                StartRelayServer::stop();
            });
        }

        // Commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                RelayServerCommand::class,
                StatusCommand::class,
                ListErrorsCommand::class,
                WatchErrorsCommand::class,
                SyncCommand::class,
            ]);
        }

        // === HOOK INTO LARAVEL'S EXCEPTION HANDLING ===
        $this->registerExceptionReporter();

        // Boot error capture
        $this->app->make(ErrorCaptureService::class)->boot();
    }

    protected function isEnabled(): bool
    {
        if (config('error-sync.force_enable', false)) {
            return true;
        }

        return $this->app->environment(
            config('error-sync.environments', ['local', 'development'])
        );
    }

    /**
     * Register hooks into Laravel's exception handling.
     */
    protected function hookIntoExceptionHandler(): void
    {
        // Use Laravel's exception handler reportable callback
        if (class_exists(\Illuminate\Foundation\Exceptions\Handler::class)) {
            \Illuminate\Support\Facades\Event::listen(
                \Illuminate\Log\Events\MessageLogged::class,
                [\NativePHP\ErrorSync\Listeners\CaptureExceptionListener::class, 'handle']
            );
        }
    }

    protected function registerExceptionReporter(): void
    {
        // Listen for all exceptions through Laravel's event system
        \Illuminate\Support\Facades\Event::listen(function (\Illuminate\Log\Events\MessageLogged $event) {
            if ($event->level === 'error' || $event->level === 'critical') {
                // Extract the exception from context if available
                $exception = $event->context['exception'] ?? null;

                // Store in the capture service
                $service = app(\NativePHP\ErrorSync\Services\ErrorCaptureService::class);

                if ($exception instanceof \Throwable) {
                    $service->captureException($exception);
                } else {
                    // Log-based error
                    $service->captureMessage($event->message, $event->level);
                }
            }
        });
    }
}
