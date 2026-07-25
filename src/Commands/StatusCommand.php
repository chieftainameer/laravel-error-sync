<?php
// src/Commands/StatusCommand.php

namespace NativePHP\ErrorSync\Commands;

use Illuminate\Console\Command;
use NativePHP\ErrorSync\Services\RelayClient;
use NativePHP\ErrorSync\Facades\ErrorSync;

class StatusCommand extends Command
{
    protected $signature = 'error-sync:status';
    protected $description = 'Check Error Sync configuration and connectivity';

    public function handle(RelayClient $relay): int
    {
        $this->info('Error Sync Status');
        $this->line(str_repeat('─', 40));

        // Environment
        $env = app()->environment();
        $enabled = in_array($env, config('error-sync.environments', []));
        $this->line('Environment: ' . ($enabled ? "✅ {$env}" : "⚠️ {$env} (disabled)"));

        // Enabled
        $active = $enabled || config('error-sync.force_enable');
        $this->line('Active: ' . ($active ? '✅ Yes' : '❌ No'));

        // Relay URL
        $this->line('Relay URL: ' . $relay->getUrl());

        // Relay reachable
        $reachable = $relay->ping();
        $this->line('Relay Server: ' . ($reachable ? '✅ Reachable' : '❌ Unreachable'));

        // Buffers
        $stats = ErrorSync::getBufferStats();
        $this->line('Buffered events:');
        foreach ($stats as $key => $count) {
            $this->line("  {$key}: {$count}");
        }

        // Triggers
        $this->line('Triggers:');
        foreach (config('error-sync.triggers', []) as $trigger => $enabled) {
            $this->line('  ' . ($enabled ? '✅' : '❌') . " {$trigger}");
        }

        $this->line(str_repeat('─', 40));

        return self::SUCCESS;
    }
}