<?php
// src/Commands/RelayServerCommand.php

namespace NativePHP\ErrorSync\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RelayServerCommand extends Command
{
    protected $signature = 'error-sync:relay 
                            {--port=9999 : Port to listen on}
                            {--output=~/agent-errors : Output directory}';
    protected $description = 'Start the error relay server on your development machine';

    public function handle(): int
    {
        $port = $this->option('port');
        $output = str_replace('~', getenv('HOME'), $this->option('output'));

        $relayScript = base_path('error-relay/error-relay.py');

        if (!file_exists($relayScript)) {
            $this->error('Relay server not found. Run: php artisan error-sync:install');
            return self::FAILURE;
        }

        // Check Python is available
        $python = $this->findPython();
        if (!$python) {
            $this->error('Python 3 not found. Please install Python 3.');
            return self::FAILURE;
        }

        $this->info("Starting Error Sync relay on port {$port}...");
        $this->info("Output: {$output}");
        $this->info("Press Ctrl+C to stop.");
        $this->newLine();

        $process = new Process([$python, $relayScript, '--port', $port, '--output', $output]);
        $process->setTimeout(null);

        return $process->run(function ($type, $line) {
            $this->output->write($line);
        });
    }

    protected function findPython(): ?string
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