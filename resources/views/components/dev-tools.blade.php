{{-- resources/views/components/dev-tools.blade.php --}}
@php
    $environments = config('error-sync.environments', ['local', 'development']);
    $isDev = app()->environment($environments) || config('error-sync.force_enable', false);
    $packageRoot = dirname((new ReflectionClass(\NativePHP\ErrorSync\ErrorSyncServiceProvider::class))->getFileName(), 2);

    $html2canvasSource = null;
    if ($isDev && config('error-sync.collect.screenshot', false)) {
        $publishedAsset = public_path('vendor/error-sync/vendor/html2canvas.min.js');
        $packageAsset = $packageRoot . '/resources/js/vendor/html2canvas.min.js';

        foreach ([$publishedAsset, $packageAsset] as $assetPath) {
            if (is_file($assetPath) && is_readable($assetPath)) {
                $html2canvasSource = file_get_contents($assetPath);
                break;
            }
        }
    }

    $annotationEditorSource = null;
    if ($isDev
        && config('error-sync.collect.screenshot', false)
        && config('error-sync.screenshot_editor.enabled', true)) {
        foreach ([
            public_path('vendor/error-sync/annotation-editor.js'),
            $packageRoot . '/resources/js/annotation-editor.js',
        ] as $assetPath) {
            if (is_file($assetPath) && is_readable($assetPath)) {
                $annotationEditorSource = file_get_contents($assetPath);
                break;
            }
        }
    }
@endphp

@if($isDev)
    {{-- Config for the JS --}}
    <script>
        window.__errorSyncConfig = {
            screenshot: {{ config('error-sync.collect.screenshot', false) ? 'true' : 'false' }},
            screenshotEditor: {{ config('error-sync.screenshot_editor.enabled', true) ? 'true' : 'false' }},
            screenshotEditorQuality: {{ (float) config('error-sync.screenshot_editor.jpeg_quality', 0.72) }},
            html2canvasUrl: '/vendor/error-sync/vendor/html2canvas.min.js',
        };
    </script>

    {{-- html2canvas for screenshots --}}
    @if(config('error-sync.collect.screenshot', false))
        @if($html2canvasSource)
            {{-- Inline the bundled library so compiled/offline WebViews do not
                 depend on resolving a public asset URL. --}}
            <script>{!! $html2canvasSource !!}</script>
        @else
            <script>
                console.error('[ErrorSync] Bundled html2canvas asset could not be read by PHP.');
            </script>
        @endif
    @endif

    {{-- Floating button --}}
    @if(config('error-sync.triggers.button', true))
        <button id="error-sync-capture-button" onclick="window.__errorSyncCapture('manual_button')"
            style="position:fixed;bottom:20px;right:20px;z-index:99998;
                   width:50px;height:50px;border-radius:25px;
                   background:#ef4444;color:white;border:none;
                   font-size:20px;cursor:pointer;
                   box-shadow:0 4px 12px rgba(239,68,68,0.4);"
            title="Send error report with screenshot (ErrorSync)"
        >📸</button>
    @endif

    {{-- Inline the editor as well so it works in offline compiled WebViews. --}}
    @if(config('error-sync.screenshot_editor.enabled', true) && $annotationEditorSource)
        <script>{!! $annotationEditorSource !!}</script>
    @endif

    {{-- Error capture JS --}}
    <script src="{{ asset('vendor/error-sync/error-capture.js') }}"></script>
@endif
