# NativePHP Error Sync

Capture errors and debugging context from a Laravel/NativePHP application, save an AI-ready report locally, and optionally relay it from a mobile device to your development machine.

Error Sync collects recent PHP and JavaScript errors, browser console messages, network requests, user actions, request context, logs, queries, and an optional screenshot. Each capture is written as JSON and Markdown so it can be pasted into an AI coding agent or inspected directly.

> **Development only.** Error reports may contain request, session, log, query, device, and screenshot data. The package is enabled only in the configured development environments by default. Do not force-enable it in production.

## Features

- Automatic PHP exception and error capture
- JavaScript errors and unhandled promise rejections
- Browser `fetch` and `XMLHttpRequest` activity
- Console logs and recent user actions
- Request, session, Laravel log, and database query context
- Optional WebView screenshot capture
- Manual capture from a floating button, shake gesture, three-finger triple tap, or keyboard shortcut
- Local JSON and Markdown reports with configurable retention
- Optional Python relay server for sending reports from a phone to a development machine
- Artisan commands for installation, status checks, watching, and browsing reports

## Requirements

- PHP 8.3 or newer
- Laravel 10, 11, 12, or 13
- Guzzle 7
- A NativePHP application, or another Laravel application rendered in a compatible WebView
- Python 3 for the optional relay server
- The phone and development machine on the same network when using a physical mobile device

## Installation

Install the package as a development dependency:

```bash
composer require --dev nativephp/error-sync
```

Laravel discovers the service provider and facade automatically. Then run the installer:

```bash
php artisan error-sync:install
```

The installer:

1. Publishes the configuration, JavaScript, Blade component, and Python relay.
2. Detects a local network address and writes `ERROR_SYNC_RELAY_URL` to `.env`.
3. Offers to add the development tools component to a Blade layout.
4. Checks whether Python 3 is available.

For an unattended install, optionally specify a layout relative to `resources/views`:

```bash
php artisan error-sync:install --no-interaction --layout=layouts/app.blade.php
```

The equivalent publish commands are:

```bash
php artisan vendor:publish --tag=error-sync-config
php artisan vendor:publish --tag=error-sync-assets
php artisan vendor:publish --tag=error-sync-views
php artisan vendor:publish --tag=error-sync-relay
```

If the installer did not update a layout, add the component immediately before `</body>`:

```blade
<x-error-sync::dev-tools />
```

Ensure the layout also has a CSRF token meta tag if the rest of the application needs it:

```blade
<meta name="csrf-token" content="{{ csrf_token() }}">
```

Clear cached configuration after changing `.env` or the package configuration:

```bash
php artisan config:clear
```

## Quick start

Set the relay URL to an address your mobile device can reach:

```dotenv
ERROR_SYNC_RELAY_URL=http://192.168.1.42:9999
```

Start the relay in a terminal on the development machine:

```bash
php artisan error-sync:relay
```

Verify the connection:

```bash
php artisan error-sync:status
```

Open the application and trigger a report using one of the following:

- Tap the red floating capture button.
- Shake the device when the NativePHP bridge exposes `onShake`.
- Triple-tap with at least three fingers within one second.
- Press `Ctrl+Shift+E` in a desktop WebView.
- Call `window.__errorSyncCapture('my_trigger')` from JavaScript.
- Call the facade from PHP.

The application always saves the report through Laravel's configured `local_backup.disk` first. With the default `local` disk, this is inside the project's `storage` directory. It then attempts to send the same report to the optional relay. A relay connection failure does not prevent project-local storage.

## How it works

```text
NativePHP WebView / Laravel application
  -> buffers errors and recent debugging context
  -> POST /_error-sync/capture
  -> writes {Laravel local disk}/error-sync/latest.{json,md}
  -> POST {ERROR_SYNC_RELAY_URL}/error
  -> optional Python relay writes storage/agent-errors/latest.md
```

PHP error handling and Laravel error/critical log events can initiate an automatic capture. The browser asset buffers JavaScript errors, console entries, network activity, and user actions until a full capture is triggered.

## Capturing from PHP

The facade is registered as `ErrorSync`:

```php
use NativePHP\ErrorSync\Facades\ErrorSync;

// Add context to the current in-memory buffer.
ErrorSync::logUserAction('checkout_started', [
    'cart_id' => $cart->id,
]);

ErrorSync::logConsole('info', 'Checkout flow started');

ErrorSync::logNetwork([
    'method' => 'POST',
    'url' => 'https://api.example.test/payments',
    'status' => 500,
    'duration' => 341,
    'error' => 'Payment provider unavailable',
]);

// Build, save, and relay a full report.
$result = ErrorSync::captureAndSend('checkout_failed');
```

You can also resolve the service directly:

```php
use NativePHP\ErrorSync\Services\ErrorCaptureService;

app(ErrorCaptureService::class)->captureException($exception);
app(ErrorCaptureService::class)->captureMessage('Something failed', 'error');
```

`captureException()` sends immediately using the `auto_exception` trigger. `captureMessage()` sends immediately only when its level is `error`.

The result of `captureAndSend()` has this shape:

```php
[
    'success' => true, // Whether the relay accepted the report
    'trigger' => 'checkout_failed',
    'errors_captured' => 1,
    'saved_locally' => true,
    'local_file' => '20260723_143012_checkout_failed',
]
```

## Browser API

Including `<x-error-sync::dev-tools />` loads the browser collector and exposes:

```js
await window.__errorSyncCapture('manual_debug');
const screenshot = await window.__errorSyncScreenshot();
```

The collector intercepts browser errors, unhandled promise rejections, console methods, `fetch`, `XMLHttpRequest`, clicks, form submissions, and history navigation. It preserves the original browser behavior after recording the event.

Screenshot capture tries these methods in order:

1. `html2canvas`, when the published vendor asset is present.
2. `window.NativePHP.captureScreenshot()`, when exposed by the NativePHP bridge.
3. A base64-encoded JSON snapshot containing visible text and viewport details.

## Artisan commands

| Command | Purpose |
| --- | --- |
| `php artisan error-sync:install` | Publish and configure the package interactively. |
| `php artisan error-sync:status` | Show environment status, relay connectivity, buffers, and triggers. |
| `php artisan error-sync:relay` | Run the Python relay in the foreground. |
| `php artisan error-sync:relay --port=9999 --output=/path/to/reports` | Use a custom relay port and output directory. |
| `php artisan error-sync:start` | Start the relay process and watch the local Markdown report. |
| `php artisan error-sync:start --no-watch` | Start the relay without the local watcher. |
| `php artisan error-sync:watch` | Watch the local `latest.md` and copy new reports to the clipboard on Windows/macOS. |
| `php artisan error-sync:watch --debug` | Show the resolved disk and watched path. |
| `php artisan error-sync:watch --once` | Check once and exit. |
| `php artisan error-sync:list` | List archived local captures. |
| `php artisan error-sync:list --latest` | Show a summary of the latest capture. |
| `php artisan error-sync:list --latest --markdown` | Print the latest Markdown report. |

You can also run the published relay directly:

```bash
python error-relay/error-relay.py --port 9999 --output storage/agent-errors
```

Install its optional clipboard dependency with:

```bash
python -m pip install -r error-relay/requirements.txt
```

The relay exposes:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/ping` | Health check. |
| `GET` | `/latest` | Return the latest Markdown report. |
| `POST` | `/error` | Accept an error payload. |

## Report locations

Every capture is stored in the Laravel project's filesystem. By default, the separate Python relay also writes its copies and screenshots to `storage/agent-errors` in the target project.

Local reports use `error-sync.local_backup.disk`, which defaults to Laravel's `local` filesystem disk. Depending on the Laravel version and application filesystem configuration, its physical root is commonly `storage/app` or `storage/app/private`. The report paths relative to that disk are:

```text
error-sync/
├── latest.json
├── latest.md
├── archive/
│   ├── YYYYMMDD_HHMMSS_trigger.json
│   └── YYYYMMDD_HHMMSS_trigger.md
└── screenshots/
    └── screenshot_YYYYMMDD_HHMMSS_trigger.jpg
```


```text
storage/agent-errors/
├── latest.md
├── error_YYYY-MM-DD_HH-MM-SS.json
└── screenshot_YYYY-MM-DD_HH-MM-SS.jpg
```

Use `php artisan error-sync:list --latest` to locate and inspect the project-local report without assuming the filesystem disk's physical root. Point your AI coding tool at that disk's `error-sync/latest.md`, or at `storage/agent-errors/latest.md` when you use the relay copy.

## Configuration

Publish `config/error-sync.php` to customize the package.

### Environment variables

| Variable | Default | Description |
| --- | --- | --- |
| `ERROR_SYNC_RELAY_URL` | `http://192.168.1.100:9999` | Base URL used by the application to reach the relay. |
| `ERROR_SYNC_RELAY_PORT` | `9999` | Port used when the package starts the relay. |
| `ERROR_SYNC_FORCE` | `false` | Enables the package outside its configured environments. Avoid in production. |
| `ERROR_SYNC_AUTO_START` | `false` | Attempts to start the relay when the package boots. |
| `ERROR_SYNC_AUTO_STOP` | `true` | Stops a relay process started by this PHP process during shutdown. |

Boolean values should use conventional Laravel `.env` values such as `true` and `false`.

### Main options

```php
return [
    'relay_url' => env('ERROR_SYNC_RELAY_URL', 'http://192.168.1.100:9999'),
    'environments' => ['local', 'development'],
    'force_enable' => env('ERROR_SYNC_FORCE', false),
    'timeout' => 3,

    'triggers' => [
        'auto' => true,
        'shake' => true,
        'gesture' => true,
        'keyboard' => true,
        'button' => true,
    ],

    'collect' => [
        'php_errors' => true,
        'js_errors' => true,
        'stack_trace' => true,
        'request' => true,
        'session' => true,
        'user_actions' => true,
        'network_requests' => true,
        'console_logs' => true,
        'laravel_logs' => true,
        'database_queries' => true,
        'cache_state' => false,
        'screenshot' => true,
    ],

    'buffer_size' => 200,
    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'token',
        'api_key',
        'secret',
        'credit_card',
    ],

    'local_backup' => [
        'enabled' => true,
        'disk' => 'local',
        'path' => 'error-sync',
        'retain_days' => 7,
    ],

    'relay_server' => [
        'port' => env('ERROR_SYNC_RELAY_PORT', 9999),
        'host' => '0.0.0.0',
        'output_dir' => env('ERROR_SYNC_OUTPUT_DIR', storage_path('agent-errors')),
        'auto_start' => env('ERROR_SYNC_AUTO_START', false),
        'auto_stop' => env('ERROR_SYNC_AUTO_STOP', true),
    ],
];
```

The in-memory buffer keeps at most `buffer_size` entries per category. Payload sections have additional limits: the latest 20 PHP errors, 20 JavaScript errors, 30 user actions, 20 network requests, 40 Laravel log lines, and 10 database queries.

## Security and privacy

Error Sync intentionally collects detailed application state. Before using it with real accounts or data:

- Keep `ERROR_SYNC_FORCE=false` and restrict `environments` to development values.
- Treat local and relay reports as sensitive files; exclude their directories from source control.
- Review `collect` and disable session, request, logs, queries, screenshots, or other sections you do not need.
- Extend `sensitive_fields` for application-specific request keys.
- Be aware that request-field redaction is shallow and does not sanitize every nested value, session value, log line, URL, screenshot, or manually supplied context.
- Use the relay only on a trusted network. It binds to all interfaces and does not implement authentication or TLS.
- Do not expose port `9999` to the public internet.

Recommended project `.gitignore` entries when using custom output paths inside the repository:

```gitignore
/error-relay/
/storage/app/error-sync/
/agent-errors/
```

## Troubleshooting

### The relay is unreachable

1. Start it with `php artisan error-sync:relay`.
2. Run `php artisan error-sync:status`.
3. Confirm the phone and development machine are on the same Wi-Fi network.
4. Set `ERROR_SYNC_RELAY_URL` to the development machine's LAN IP, not `localhost`.
5. Allow the configured port through the development machine's firewall.
6. Run `php artisan config:clear` after changing `.env`.

`localhost` from a physical phone refers to the phone, not the development machine.

### The package or floating button is not active

Check that `APP_ENV` is `local` or `development`, or add the current environment to `error-sync.environments`. Also confirm that `<x-error-sync::dev-tools />` is present in the rendered layout and that published assets exist under `public/vendor/error-sync`.

### Screenshots are missing

Confirm `collect.screenshot` is enabled. The component loads `public/vendor/error-sync/vendor/html2canvas.min.js` first and falls back to the pinned CDN build when that local asset is absent. For offline mobile development, provide the local asset.

### Reports are saved locally but the UI says sync failed

The `success` field represents relay delivery, not local persistence. Inspect `saved_locally` and `local_file` in the response, or run:

```bash
php artisan error-sync:list --latest
```

### Python or clipboard integration is unavailable

Install Python 3 and make sure `python3` or `python` is available on `PATH`. Install `error-relay/requirements.txt` for relay clipboard support. The PHP watcher copies to the clipboard on Windows and macOS; unsupported platforms still retain the report on disk.

## Testing

Install development dependencies and run PHPUnit:

```bash
composer install
vendor/bin/phpunit
```

On Windows PowerShell:

```powershell
composer install
vendor\bin\phpunit
```

## Contributing

Bug reports and pull requests are welcome. Keep changes focused, follow the existing PSR/Laravel style, add or update tests for behavior changes, and verify the test suite before opening a pull request.

## License

NativePHP Error Sync is open-source software licensed under the [MIT License](https://opensource.org/license/mit/).
