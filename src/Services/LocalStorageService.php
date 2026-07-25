<?php
// src/Services/LocalStorageService.php

namespace NativePHP\ErrorSync\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class LocalStorageService
{
    protected string $disk;
    protected string $basePath;
    
    public function __construct()
    {
        $this->disk = config('error-sync.local_backup.disk', 'local');
        $this->basePath = config('error-sync.local_backup.path', 'error-sync');
    }
    
    /**
     * Save an error report locally.
     */
    public function save(array $payload, ?string $screenshotData = null): string
    {
        $filename = now()->format('Ymd_His') . '_' . ($payload['trigger'] ?? 'unknown');
        
        // 1. Save raw JSON
        $jsonPayload = $payload;
        if ($screenshotData) {
            $jsonPayload['screenshot_saved'] = true;
        }
        
        Storage::disk($this->disk)->put(
            $this->basePath . '/archive/' . $filename . '.json',
            json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        Storage::disk($this->disk)->put(
            $this->basePath . '/latest.json',
            json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        
        // 2. Save screenshot if present
        $screenshotPath = null;
        if ($screenshotData) {
            $screenshotPath = $this->saveScreenshot($screenshotData, $filename);
        }
        
        // 3. Generate and save markdown (with embedded screenshot)
        $markdown = $this->generateMarkdown($payload, $screenshotPath);
        
        Storage::disk($this->disk)->put(
            $this->basePath . '/archive/' . $filename . '.md',
            $markdown
        );
        
        Storage::disk($this->disk)->put(
            $this->basePath . '/latest.md',
            $markdown
        );
        
        // 4. Clean old files
        $this->cleanup();
        
        return $filename;
    }
    
    /**
     * Save a screenshot to disk and return the path.
     */
    protected function saveScreenshot(string $screenshotData, string $filename): ?string
    {
        try {
            // Handle data URI format: data:image/jpeg;base64,...
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $screenshotData, $matches)) {
                $format = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
                $imageData = base64_decode($matches[2]);
                
                $screenshotFilename = 'screenshot_' . $filename . '.' . $format;
                $path = $this->basePath . '/screenshots/' . $screenshotFilename;
                
                Storage::disk($this->disk)->put($path, $imageData);
                
                return $path;
            }
            
            // Handle raw base64
            if (strlen($screenshotData) > 100) {
                $imageData = base64_decode($screenshotData);
                if ($imageData !== false) {
                    $screenshotFilename = 'screenshot_' . $filename . '.png';
                    $path = $this->basePath . '/screenshots/' . $screenshotFilename;
                    
                    Storage::disk($this->disk)->put($path, $imageData);
                    
                    return $path;
                }
            }
        } catch (\Throwable) {
            // Silently fail
        }
        
        return null;
    }
    
    /**
     * Get base64 data URI for a saved screenshot.
     */
    protected function getScreenshotBase64(?string $screenshotPath): ?string
    {
        if (!$screenshotPath) {
            return null;
        }

        try {
            if (Storage::disk($this->disk)->exists($screenshotPath)) {
                $imageData = Storage::disk($this->disk)->get($screenshotPath);
                
                // Detect mime type from extension
                $ext = strtolower(pathinfo($screenshotPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/png',
                };
                
                return 'data:' . $mime . ';base64,' . base64_encode($imageData);
            }
        } catch (\Throwable) {
            // Silently fail
        }

        return null;
    }
    
    /**
     * Generate a markdown report with embedded screenshot.
     */
    protected function generateMarkdown(array $p, ?string $screenshotPath = null): string
    {
        $appName = config('app.name', 'Unknown App');
        $timestamp = $p['capturedAt'] ?? now()->toIso8601String();
        
        $lines = [
            "# Bug Report: {$appName}",
            "",
            "| Field | Value |",
            "|-------|-------|",
            "| **Captured** | {$timestamp} |",
            "| **Trigger** | " . ($p['trigger'] ?? 'unknown') . " |",
            "| **Environment** | " . ($p['app']['env'] ?? 'unknown') . " |",
            "| **PHP** | " . ($p['app']['php'] ?? '?') . " |",
            "| **Laravel** | " . ($p['app']['laravel'] ?? '?') . " |",
            "",
        ];
        
        // === SCREENSHOT (EMBEDDED AS BASE64) ===
        if ($screenshotPath) {
            $base64 = $this->getScreenshotBase64($screenshotPath);
            if ($base64) {
                $lines[] = "## Screenshot";
                $lines[] = "![Screenshot](" . $base64 . ")";
                $lines[] = "";
            }
        }
        
        // Error
        $lines[] = "## Error";
        $lines[] = "```";
        $lines[] = $p['error'] ?? 'No error message';
        $lines[] = "```";
        $lines[] = "";
        
        if ($p['errorFile'] ?? null) {
            $lines[] = "- **File:** `{$p['errorFile']}`";
        }
        if ($p['errorLine'] ?? null) {
            $lines[] = "- **Line:** {$p['errorLine']}";
        }
        if ($p['errorType'] ?? null) {
            $lines[] = "- **Type:** `{$p['errorType']}`";
        }
        $lines[] = "";
        
        // Stack trace
        if ($p['stackTrace'] ?? null) {
            $lines[] = "## Stack Trace";
            $lines[] = "```";
            $stack = strlen($p['stackTrace']) > 3000 
                ? substr($p['stackTrace'], 0, 3000) . "\n... [truncated]" 
                : $p['stackTrace'];
            $lines[] = $stack;
            $lines[] = "```";
            $lines[] = "";
        }
        
        // Request
        if ($p['route'] ?? null) {
            $lines[] = "## Request";
            $lines[] = "| Key | Value |";
            $lines[] = "|-----|-------|";
            $lines[] = "| URL | `{$p['url']}` |";
            $lines[] = "| Route | `{$p['route']}` |";
            $lines[] = "| Method | `{$p['method']}` |";
            $lines[] = "";
        }
        
        // User actions
        if (!empty($p['userActions'])) {
            $lines[] = "## User Actions (last 20)";
            foreach (array_slice($p['userActions'], -20) as $a) {
                $ctx = $a['context'] ?? [];
                $desc = $ctx['text'] ?? $ctx['dataAction'] ?? $a['action'] ?? '?';
                $lines[] = "- {$desc}";
            }
            $lines[] = "";
        }
        
        // Network
        if (!empty($p['networkRequests'])) {
            $lines[] = "## Network Requests";
            $lines[] = "| Status | Method | URL |";
            $lines[] = "|--------|--------|-----|";
            foreach (array_slice($p['networkRequests'], -15) as $r) {
                $status = $r['status'] ?? '?';
                $icon = ($status && $status < 400) ? 'OK' : 'ERR';
                $url = strlen($r['url'] ?? '') > 80 ? substr($r['url'], 0, 77) . '...' : ($r['url'] ?? '');
                $lines[] = "| {$icon} {$status} | `{$r['method']}` | `{$url}` |";
            }
            $lines[] = "";
        }
        
        // Console logs
        if (!empty($p['consoleLogs'])) {
            $lines[] = "## Console Logs";
            $lines[] = "```";
            foreach (array_slice($p['consoleLogs'], -20) as $l) {
                $level = strtoupper($l['level'] ?? 'LOG');
                $lines[] = "[{$level}] {$l['message']}";
            }
            $lines[] = "```";
            $lines[] = "";
        }
        
        $lines[] = "---";
        $lines[] = "*Analyze this error and suggest a fix with specific file paths.*";
        
        return implode("\n", $lines);
    }
    
    /**
     * Get the latest error report.
     */
    public function getLatest(): ?array
    {
        $json = Storage::disk($this->disk)->get($this->basePath . '/latest.json');
        
        if (!$json) {
            return null;
        }
        
        return json_decode($json, true);
    }
    
    /**
     * Get all archived errors.
     */
    public function getArchive(): array
    {
        $files = Storage::disk($this->disk)->files($this->basePath . '/archive');
        
        $errors = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $content = Storage::disk($this->disk)->get($file);
                $errors[] = [
                    'file' => $file,
                    'data' => json_decode($content, true),
                ];
            }
        }
        
        usort($errors, fn($a, $b) => $b['file'] <=> $a['file']);
        
        return $errors;
    }
    
    /**
     * Get the absolute path to the latest markdown file.
     */
    public function getLatestMarkdownPath(): string
    {
        return Storage::disk($this->disk)->path($this->basePath . '/latest.md');
    }
    
    /**
     * Clean up old error files.
     */
    protected function cleanup(): void
    {
        $retainDays = config('error-sync.local_backup.retain_days', 7);
        $cutoff = now()->subDays($retainDays);
        
        $directories = ['archive', 'screenshots'];
        
        foreach ($directories as $dir) {
            $files = Storage::disk($this->disk)->files($this->basePath . '/' . $dir);
            
            foreach ($files as $file) {
                try {
                    $lastModified = Storage::disk($this->disk)->lastModified($file);
                    if ($lastModified < $cutoff->timestamp) {
                        Storage::disk($this->disk)->delete($file);
                    }
                } catch (\Throwable) {
                    // Skip
                }
            }
        }
    }
}