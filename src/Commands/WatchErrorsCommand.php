<?php
// src/Commands/WatchErrorsCommand.php

namespace NativePHP\ErrorSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use NativePHP\ErrorSync\Listeners\StartRelayServer;

class WatchErrorsCommand extends Command
{
    protected $signature = 'error-sync:watch 
                            {--debug : Show debug information}
                            {--once : Check once and exit}';
    protected $description = 'Watch for new errors and copy to clipboard';

    public function handle(): int
    {
        $latestFile = $this->resolveLatestFilePath();

        if ($this->option('debug')) {
            $this->line("Disk: " . config('error-sync.local_backup.disk', 'local'));
            $this->line("Watching: {$latestFile}");
            $this->line("Directory exists: " . (File::isDirectory(dirname($latestFile)) ? 'YES' : 'NO'));
            $this->line("File exists: " . (File::exists($latestFile) ? 'YES' : 'NO'));
        }

        if (!File::isDirectory(dirname($latestFile))) {
            File::makeDirectory(dirname($latestFile), 0755, true);
        }

        $lastModified = File::exists($latestFile) ? File::lastModified($latestFile) : 0;

        $this->info('Watching for errors...');
        $this->line("File: {$latestFile}");
        $this->info('Press Ctrl+C to stop');
        $this->line('');

        if ($this->option('once')) {
            $this->checkOnce($latestFile, $lastModified);
            return self::SUCCESS;
        }

        while (true) {
            $relayOutput = StartRelayServer::drainOutput();
            if ($relayOutput !== '') {
                $this->output->write($relayOutput);
            }

            clearstatcache(true, $latestFile);

            if (File::exists($latestFile)) {
                $currentModified = File::lastModified($latestFile);

                if ($currentModified > $lastModified) {
                    $lastModified = $currentModified;
                    $this->onNewError($latestFile);
                }
            }

            sleep(1);
        }
    }

    protected function resolveLatestFilePath(): string
    {
        $disk = config('error-sync.local_backup.disk', 'local');
        return Storage::disk($disk)->path('error-sync/latest.md');
    }

    protected function onNewError(string $file): void
    {
        $content = File::get($file);
        $copied = $this->copyToClipboard($content);

        $this->line('');
        $this->line(str_repeat('=', 60));
        $this->info('NEW ERROR CAPTURED! ' . now()->format('H:i:s'));
        $this->line(str_repeat('=', 60));
        $this->line($this->extractPreview($content));
        $this->line(str_repeat('=', 60));
        $this->line($copied ? 'Copied to clipboard' : 'Could not copy');
        $this->line('');
    }

    protected function extractPreview(string $content): string
    {
        $lines = array_filter(explode("\n", $content), fn($l) => trim($l) !== '');
        return implode("\n", array_slice($lines, 0, 15));
    }

    protected function copyToClipboard(string $content): bool
    {
        try {
            $os = PHP_OS_FAMILY;
            if ($os === 'Windows') {
                $p = proc_open('clip', [0 => ['pipe', 'r']], $pipes);
                fwrite($pipes[0], $content);
                fclose($pipes[0]);
                proc_close($p);
                return true;
            } elseif ($os === 'Darwin') {
                $p = proc_open('pbcopy', [0 => ['pipe', 'r']], $pipes);
                fwrite($pipes[0], $content);
                fclose($pipes[0]);
                proc_close($p);
                return true;
            }
        } catch (\Throwable) {}
        return false;
    }

    protected function checkOnce(string $file, int $lastModified): void
    {
        if (File::exists($file)) {
            if (File::lastModified($file) > $lastModified) {
                $this->onNewError($file);
            } else {
                $this->line('No new errors.');
            }
        } else {
            $this->warn('No error file yet. Trigger an error first.');
        }
    }
}
