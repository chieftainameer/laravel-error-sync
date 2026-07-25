<?php
// src/Commands/ListErrorsCommand.php

namespace NativePHP\ErrorSync\Commands;

use Illuminate\Console\Command;
use NativePHP\ErrorSync\Services\LocalStorageService;

class ListErrorsCommand extends Command
{
    protected $signature = 'error-sync:list 
                            {--latest : Show only the latest error}
                            {--markdown : Output the markdown directly}';
    protected $description = 'List captured errors stored locally';

    public function handle(LocalStorageService $storage): int
    {
        if ($this->option('latest')) {
            return $this->showLatest($storage);
        }

        $errors = $storage->getArchive();

        if (empty($errors)) {
            $this->info('No errors captured yet. Trigger an error and try again.');
            return self::SUCCESS;
        }

        $this->info('Captured Errors');
        $this->line(str_repeat('-', 60));

        foreach ($errors as $index => $error) {
            $data = $error['data'];
            $this->line(sprintf(
                '[%d] %s | %s | %s',
                $index + 1,
                $data['capturedAt'] ?? 'Unknown',
                $data['trigger'] ?? '?',
                ($data['error'] ?? 'No error')[60]
            ));
        }

        $this->line(str_repeat('-', 60));
        $this->line('View latest: php artisan error-sync:list --latest');
        $this->line('View markdown: php artisan error-sync:list --latest --markdown');

        return self::SUCCESS;
    }

    protected function showLatest(LocalStorageService $storage): int
    {
        $latest = $storage->getLatest();

        if (!$latest) {
            $this->info('No errors captured yet.');
            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $markdown = file_get_contents($storage->getLatestMarkdownPath());
            $this->line($markdown);
            return self::SUCCESS;
        }

        $this->info('Latest Error');
        $this->line(str_repeat('-', 60));
        $this->line('Error: ' . ($latest['error'] ?? 'Unknown'));
        $this->line('File: ' . ($latest['errorFile'] ?? 'N/A'));
        $this->line('Line: ' . ($latest['errorLine'] ?? 'N/A'));
        $this->line('Trigger: ' . ($latest['trigger'] ?? '?'));
        $this->line('Captured: ' . ($latest['capturedAt'] ?? '?'));
        $this->line('');
        $this->line('Full report: storage/app/error-sync/latest.md');

        return self::SUCCESS;
    }
}