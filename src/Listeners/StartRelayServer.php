<?php
// src/Listeners/StartRelayServer.php

namespace NativePHP\ErrorSync\Listeners;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class StartRelayServer
{
    protected static ?Process $process = null;
    
    /**
     * Start the relay server if it's not already running.
     */
    public static function start(): bool
    {
        // Only in local/development
        if (!app()->environment(config('error-sync.environments', ['local', 'development']))) {
            return false;
        }
        
        // Check if already running
        if (self::isRunning()) {
            return false;
        }
        
        $relayScript = base_path('error-relay/error-relay.py');
        
        if (!file_exists($relayScript)) {
            return false;
        }
        
        $port = config('error-sync.relay_server.port', 9999);
        $output = config('error-sync.relay_server.output_dir') ?: storage_path('agent-errors');
        $python = self::findPython();
        
        if (!$python) {
            return false;
        }
        
        // -u is important here: the relay runs behind a pipe, where Python would
        // otherwise buffer screenshot and request diagnostics indefinitely.
        self::$process = new Process([$python, '-u', $relayScript, '--port', $port, '--output', $output]);
        self::$process->setTimeout(null);
        self::$process->start();
        
        // Give it a moment to start
        usleep(500000);
        
        if (self::$process->isRunning()) {
            Log::info("Error Sync relay server started on port {$port}");
            return true;
        }

        return false;
    }
    
    /**
     * Stop the relay server.
     */
    public static function stop(): void
    {
        if (self::$process && self::$process->isRunning()) {
            self::$process->stop(2);
            self::$process = null;
        }
    }

    /**
     * Return output produced by the asynchronously running relay since the
     * previous call. Commands must drain this periodically to display it.
     */
    public static function drainOutput(): string
    {
        if (!self::$process) {
            return '';
        }

        return self::$process->getIncrementalOutput()
            . self::$process->getIncrementalErrorOutput();
    }
    
    /**
     * Check if relay server is already running.
     */
    protected static function isRunning(): bool
    {
        $port = config('error-sync.relay_server.port', 9999);
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        
        return false;
    }
    
    /**
     * Find Python executable.
     */
    protected static function findPython(): ?string
    {
        foreach (['python3', 'python'] as $cmd) {
            $process = new Process([$cmd, '--version']);
            $process->run();
            if ($process->isSuccessful()) {
                return $cmd;
            }
        }
        return null;
    }
}
