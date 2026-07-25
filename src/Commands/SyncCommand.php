<?php
// src/Commands/SyncCommand.php

namespace NativePHP\ErrorSync\Commands;

use Illuminate\Console\Command;
use NativePHP\ErrorSync\Listeners\StartRelayServer;

class SyncCommand extends Command
{
    protected $signature = 'error-sync:start 
                            {--port=9999 : Relay server port}
                            {--no-watch : Dont watch for errors, just start relay}';
    protected $description = 'Start the relay server and watch for errors';

    public function handle(): int
    {
        $this->info('Starting Error Sync...');
        
        // 1. Start relay server
        $this->line('[1/2] Starting relay server...');
        $relayStarted = StartRelayServer::start();
        $relayOutput = StartRelayServer::drainOutput();
        if ($relayOutput !== '') {
            $this->output->write($relayOutput);
        }
        if ($relayStarted) {
            $this->info('[OK] Relay server running on port ' . config('error-sync.relay_server.port', 9999));
        } else {
            $this->warn('[!] Relay was already running or could not be started. Live relay diagnostics are only available when this command starts it.');
        }
        
        // 2. Start watching (unless --no-watch)
        if (!$this->option('no-watch')) {
            $this->line('[2/2] Watching for errors...');
            $this->info('[OK] Ready to capture errors');
            $this->line('');
            $this->line('Triggers:');
            $this->line('  - Auto: Any unhandled exception');
            $this->line('  - Manual: Click the button or shake phone');
            $this->line('  - CLI: php artisan error-sync:capture');
            $this->line('');
            $this->line('Press Ctrl+C to stop everything');
            $this->line('');
            
            // Run the watch command
            $this->call('error-sync:watch');
        } else {
            $this->info('[OK] Relay server only (no watch)');
            $this->line('Run php artisan error-sync:watch to start watching');
        }
        
        return self::SUCCESS;
    }
}
