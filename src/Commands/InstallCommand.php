<?php
// src/Commands/InstallCommand.php

namespace NativePHP\ErrorSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'error-sync:install
                            {--layout= : Layout file to add dev tools to}';
    protected $description = 'Install and configure Error Sync for NativePHP';

    protected array $steps = [];
    protected array $warnings = [];

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║   NativePHP Error Sync - Installer      ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->info('');

        // Step 1: Publish files
        $this->publishFiles();

        // Step 2: Auto-detect laptop IP and update .env
        $this->configureRelayUrl();

        // Step 3: Add dev tools to layout
        $this->addDevToolsToLayout();

        // Step 4: Verify Python is available
        $this->checkPython();

        // Step 5: Summary
        $this->showSummary();

        return self::SUCCESS;
    }

    // ============================================
    // STEP 1: PUBLISH FILES
    // ============================================

    protected function publishFiles(): void
    {
        $this->line('[1/4] Publishing files...');
        $this->line('');

        $packagePath = realpath(__DIR__ . '/../..');
        $appPath = base_path();

        // Config
        if (!File::exists(config_path('error-sync.php'))) {
            copy(
                $packagePath . '/config/error-sync.php',
                $appPath . '/config/error-sync.php'
            );
            $this->steps[] = 'Config published: config/error-sync.php';
        } else {
            $this->warn('Config already exists, skipping');
        }

        // JS assets
        $jsDir = $appPath . '/public/vendor/error-sync';
        $vendorJsDir = $jsDir . '/vendor';
        
        if (!File::isDirectory($jsDir)) {
            File::makeDirectory($jsDir, 0755, true);
        }
        if (!File::isDirectory($vendorJsDir)) {
            File::makeDirectory($vendorJsDir, 0755, true);
        }

        // Copy error-capture.js
        $sourceJs = $packagePath . '/resources/js/error-capture.js';
        if (File::exists($sourceJs)) {
            copy($sourceJs, $jsDir . '/error-capture.js');
            $this->steps[] = 'JS published: public/vendor/error-sync/error-capture.js';
        }

        // Copy html2canvas
        $sourceHtml2canvas = $packagePath . '/resources/js/vendor/html2canvas.min.js';
        if (File::exists($sourceHtml2canvas)) {
            copy($sourceHtml2canvas, $vendorJsDir . '/html2canvas.min.js');
            $this->steps[] = 'JS published: public/vendor/error-sync/vendor/html2canvas.min.js';
        } else {
            $this->warnings[] = 'html2canvas not found — screenshots disabled. Download it from: https://html2canvas.hertzen.com';
        }

        // Views
        $viewDir = $appPath . '/resources/views/vendor/error-sync/components';
        if (!File::isDirectory($viewDir)) {
            File::makeDirectory($viewDir, 0755, true);
        }
        copy(
            $packagePath . '/resources/views/components/dev-tools.blade.php',
            $viewDir . '/dev-tools.blade.php'
        );
        $this->steps[] = 'View published: resources/views/vendor/error-sync/components/dev-tools.blade.php';

        // Relay server
        $relayDir = $appPath . '/error-relay';
        if (!File::isDirectory($relayDir)) {
            File::makeDirectory($relayDir, 0755, true);
        }
        copy(
            $packagePath . '/relay-server/error-relay.py',
            $relayDir . '/error-relay.py'
        );
        copy(
            $packagePath . '/relay-server/requirements.txt',
            $relayDir . '/requirements.txt'
        );
        $this->steps[] = 'Relay server published: error-relay/error-relay.py';

        $this->line('');
    }

    // ============================================
    // STEP 2: AUTO-DETECT LAPTOP IP
    // ============================================

    protected function configureRelayUrl(): void
    {
        $this->line('[2/4] Configuring relay server connection...');
        $this->line('');

        $ips = $this->getLocalIps();

        if (empty($ips)) {
            $this->warn('Could not detect local IP. Please set it manually in .env');
            return;
        }

        // Filter to likely WiFi IPs (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
        $privateIps = array_filter($ips, function ($ip) {
            return $this->isPrivateIp($ip);
        });

        if (empty($privateIps)) {
            $privateIps = $ips;
        }

        $this->line('I found these network addresses on your machine:');
        $this->line('');

        $options = [];
        foreach ($privateIps as $index => $ip) {
            $num = $index + 1;
            $label = $this->getIpLabel($ip);
            $options[$num] = $ip;
            $this->line("  [{$num}] {$ip} {$label}");
        }
        $this->line("  [C] Enter custom IP");
        $this->line("  [S] Skip (configure later)");
        $this->line('');

        if ($this->option('no-interaction')) {
            $choice = 1;
        } else {
            $choice = $this->ask('Which IP should the mobile app connect to?', '1');
        }

        $relayUrl = null;

        if (strtolower($choice) === 's') {
            $this->warn('Skipped. Set ERROR_SYNC_RELAY_URL in .env later.');
            $this->warnings[] = 'Relay URL not configured — run php artisan error-sync:status to check';
            return;
        }

        if (strtolower($choice) === 'c') {
            $customIp = $this->ask('Enter your laptop IP address');
            $port = $this->ask('Port', '9999');
            $relayUrl = "http://{$customIp}:{$port}";
        } elseif (isset($options[(int)$choice])) {
            $ip = $options[(int)$choice];
            $port = '9999';
            $relayUrl = "http://{$ip}:{$port}";
        } else {
            // Default to first
            $ip = reset($privateIps);
            $relayUrl = "http://{$ip}:9999";
        }

        if ($relayUrl) {
            $this->updateEnvFile('ERROR_SYNC_RELAY_URL', $relayUrl);
            $this->steps[] = "Relay URL set: {$relayUrl}";
        }

        // Show mobile warning
        $this->line('');
        $this->line('┌─────────────────────────────────────────────────┐');
        $this->line('│  IMPORTANT FOR MOBILE DEVELOPMENT              │');
        $this->line('│                                                 │');
        $this->line('│  Make sure your phone and laptop are connected  │');
        $this->line('│  to the SAME WiFi network.                      │');
        $this->line('│                                                 │');
        $this->line('│  If you change networks, update the IP in .env: │');
        $this->line('│  ERROR_SYNC_RELAY_URL=http://NEW_IP:9999       │');
        $this->line('│                                                 │');
        $this->line('│  Run php artisan error-sync:status to verify    │');
        $this->line('└─────────────────────────────────────────────────┘');
        $this->line('');
    }

    protected function getLocalIps(): array
    {
        $ips = [];

        // Method 1: Try socket connection
        try {
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($socket) {
                socket_connect($socket, '8.8.8.8', 80);
                socket_getsockname($socket, $ip);
                socket_close($socket);
                if ($ip && $ip !== '127.0.0.1') {
                    $ips[] = $ip;
                }
            }
        } catch (\Throwable) {}

        // Method 2: Try hostname -I (Linux/macOS)
        try {
            $output = shell_exec('hostname -I 2>/dev/null');
            if ($output) {
                foreach (explode(' ', trim($output)) as $ip) {
                    $ip = trim($ip);
                    if ($ip && !in_array($ip, $ips) && $ip !== '127.0.0.1') {
                        $ips[] = $ip;
                    }
                }
            }
        } catch (\Throwable) {}

        // Method 3: Try ipconfig (Windows)
        try {
            $output = shell_exec('ipconfig 2>/dev/null');
            if ($output) {
                preg_match_all('/IPv4 Address[.\s]*: ([\d.]+)/', $output, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $ip) {
                        if (!in_array($ip, $ips) && $ip !== '127.0.0.1') {
                            $ips[] = $ip;
                        }
                    }
                }
            }
        } catch (\Throwable) {}

        // Method 4: Try ifconfig (macOS/Linux)
        try {
            $output = shell_exec('ifconfig 2>/dev/null');
            if ($output) {
                preg_match_all('/inet ([\d.]+)/', $output, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $ip) {
                        if (!in_array($ip, $ips) && $ip !== '127.0.0.1') {
                            $ips[] = $ip;
                        }
                    }
                }
            }
        } catch (\Throwable) {}

        // Fallback: include localhost
        if (empty($ips)) {
            $ips[] = '127.0.0.1';
        }

        return $ips;
    }

    protected function isPrivateIp(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) return false;

        $first = (int)$parts[0];
        $second = (int)$parts[1];

        // 192.168.x.x
        if ($first === 192 && $second === 168) return true;
        // 10.x.x.x
        if ($first === 10) return true;
        // 172.16-31.x.x
        if ($first === 172 && $second >= 16 && $second <= 31) return true;

        return false;
    }

    protected function getIpLabel(string $ip): string
    {
        $parts = explode('.', $ip);
        $first = (int)$parts[0];

        if ($first === 192 && (int)$parts[1] === 168) return '(home/office WiFi)';
        if ($first === 10) return '(corporate VPN)';
        if ($first === 172) return '(Docker/hypervisor)';
        if ($ip === '127.0.0.1') return '(localhost only)';

        return '';
    }

    protected function updateEnvFile(string $key, string $value): void
    {
        $envPath = $this->resolveEnvPath();

        if (!File::exists($envPath)) {
            File::put($envPath, "{$key}={$value}\n");
            return;
        }

        $content = File::get($envPath);

        // Check if key already exists
        if (str_contains($content, $key . '=')) {
            // Replace existing
            $content = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$value}",
                $content
            );
        } else {
            // Append to end
            $content .= "\n{$key}={$value}\n";
        }

        File::put($envPath, $content);
    }

    protected function resolveEnvPath(): string
    {
        $app = $this->laravel;

        if ($app instanceof \Illuminate\Contracts\Foundation\Application) {
            $environmentPath = $app->environmentPath();
            $environmentFile = $app->environmentFile();

            if ($environmentFile) {
                return $environmentPath . DIRECTORY_SEPARATOR . $environmentFile;
            }
        }

        return base_path('.env');
    }

    // ============================================
    // STEP 3: ADD DEV TOOLS TO LAYOUT
    // ============================================

    protected function addDevToolsToLayout(): void
    {
        $this->line('[3/4] Adding dev tools to your layout...');
        $this->line('');

        $layoutOption = $this->option('layout');

        if ($layoutOption) {
            $layoutPath = resource_path('views/' . $layoutOption);
            $this->injectDevTools($layoutPath);
            return;
        }

        // Find layout files
        $layouts = $this->findLayoutFiles();

        if (empty($layouts)) {
            $this->warn('No layout files found. Add this manually to your layout:');
            $this->line('  <x-error-sync::dev-tools />');
            $this->warnings[] = 'Dev tools not added to layout — add <x-error-sync::dev-tools /> manually';
            return;
        }

        $this->line('I found these layout files:');
        $this->line('');

        $options = [];
        foreach ($layouts as $index => $layout) {
            $num = $index + 1;
            $options[$num] = $layout;
            $this->line("  [{$num}] {$layout}");
        }
        $this->line("  [A] Add to ALL layouts");
        $this->line("  [S] Skip (I'll add it manually)");
        $this->line('');

        if ($this->option('no-interaction')) {
            $choice = 'a';
        } else {
            $choice = $this->ask('Which layout should include the dev tools?', '1');
        }

        if (strtolower($choice) === 's') {
            $this->warn('Skipped. Add this to your layout: <x-error-sync::dev-tools />');
            $this->warnings[] = 'Add <x-error-sync::dev-tools /> to your layout manually';
            return;
        }

        if (strtolower($choice) === 'a') {
            foreach ($layouts as $layout) {
                $this->injectDevTools($layout);
            }
            $this->steps[] = 'Dev tools added to ALL layouts';
            return;
        }

        if (isset($options[(int)$choice])) {
            $this->injectDevTools($options[(int)$choice]);
            $this->steps[] = 'Dev tools added to: ' . $options[(int)$choice];
        }
    }

    protected function findLayoutFiles(): array
    {
        $layouts = [];
        $viewsPath = resource_path('views');

        if (!File::isDirectory($viewsPath)) {
            return $layouts;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' || $file->getExtension() === 'blade.php') {
                $content = File::get($file->getPathname());
                
                // Check if it looks like a layout (has <html> or </body> or @yield)
                if (str_contains($content, '</body>') || 
                    str_contains($content, '<html') ||
                    str_contains($content, '@yield') ||
                    str_contains($content, 'layouts.')) {
                    
                    $relativePath = str_replace($viewsPath . '/', '', $file->getPathname());
                    $layouts[] = $relativePath;
                }
            }
        }

        // Prioritize common layout names
        $priority = ['app.blade.php', 'layouts/app.blade.php', 'layout.blade.php', 'layouts/layout.blade.php'];
        usort($layouts, function ($a, $b) use ($priority) {
            $aPriority = array_search(basename($a), $priority) !== false ? 0 : 1;
            $bPriority = array_search(basename($b), $priority) !== false ? 0 : 1;
            return $aPriority - $bPriority;
        });

        return array_slice($layouts, 0, 10); // Max 10
    }

    protected function injectDevTools(string $layoutPath): void
    {
        $fullPath = resource_path('views/' . $layoutPath);

        if (!File::exists($fullPath)) {
            $this->warn("Layout not found: {$layoutPath}");
            return;
        }

        $content = File::get($fullPath);

        // Check if already added
        if (str_contains($content, 'error-sync::dev-tools')) {
            $this->line("  Already present in: {$layoutPath}");
            return;
        }

        // Inject before </body>
        $tag = '<x-error-sync::dev-tools />';

        if (str_contains($content, '</body>')) {
            $content = str_replace(
                '</body>',
                "    {$tag}\n</body>",
                $content
            );
        } elseif (str_contains($content, '{{ $slot }}')) {
            $content = str_replace(
                '{{ $slot }}',
                "{{ \$slot }}\n    {$tag}",
                $content
            );
        } else {
            // Append at end
            $content .= "\n{$tag}\n";
        }

        File::put($fullPath, $content);
        $this->line("  [OK] Added to: {$layoutPath}");
    }

    // ============================================
    // STEP 4: CHECK PYTHON
    // ============================================

    protected function checkPython(): void
    {
        $this->line('');
        $this->line('[4/4] Checking Python installation...');

        $python = null;
        foreach (['python3', 'python'] as $cmd) {
            $output = shell_exec("{$cmd} --version 2>&1");
            if ($output && str_contains($output, 'Python')) {
                $python = $cmd;
                $this->line("  [OK] Found: " . trim($output));
                $this->steps[] = 'Python detected: ' . trim($output);
                break;
            }
        }

        if (!$python) {
            $this->warn('  Python 3 not found. The relay server requires Python 3.');
            $this->warn('  Install from: https://python.org/downloads/');
            $this->warnings[] = 'Python 3 required for relay server — download from python.org';
        }
    }

    // ============================================
    // SUMMARY
    // ============================================

    protected function showSummary(): void
    {
        $this->line('');
        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║   Installation Complete!                ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->info('');

        if (!empty($this->steps)) {
            $this->line('What was set up:');
            foreach ($this->steps as $step) {
                $this->line("  [OK] {$step}");
            }
        }

        if (!empty($this->warnings)) {
            $this->line('');
            $this->line('Warnings:');
            foreach ($this->warnings as $warning) {
                $this->warn("  [!] {$warning}");
            }
        }

        $this->line('');
        $this->line('Next steps:');
        $this->line('  1. Start the system: php artisan error-sync:start');
        $this->line('  2. Check status:     php artisan error-sync:status');
        $this->line('  3. Visit your app and look for the dev tools button');
        $this->line('');
        $this->line('For mobile development:');
        $this->line('  - Ensure phone and laptop are on the SAME WiFi');
        $this->line('  - The button & gestures work in your WebView');
        $this->line('');
    }
}