{{-- resources/views/components/dev-tools.blade.php --}}
@php
    $environments = config('error-sync.environments', ['local', 'development']);
    $isDev = app()->environment($environments) || config('error-sync.force_enable', false);
@endphp

@if($isDev)
    {{-- Config for the JS --}}
    <script>
        window.__errorSyncConfig = {
            screenshot: {{ config('error-sync.collect.screenshot', false) ? 'true' : 'false' }},
        };
    </script>

    {{-- html2canvas for screenshots --}}
    @if(config('error-sync.collect.screenshot', false))
        <script src="{{ asset('vendor/error-sync/vendor/html2canvas.min.js') }}"></script>
    @endif

    {{-- Floating button --}}
    @if(config('error-sync.triggers.button', true))
        <button onclick="window.__errorSyncCapture('manual_button')"
            style="position:fixed;bottom:20px;right:20px;z-index:99998;
                   width:50px;height:50px;border-radius:25px;
                   background:#ef4444;color:white;border:none;
                   font-size:20px;cursor:pointer;
                   box-shadow:0 4px 12px rgba(239,68,68,0.4);"
            title="Send error report with screenshot (ErrorSync)"
        >📸</button>
    @endif

    {{-- Error capture JS --}}
    <script src="{{ asset('vendor/error-sync/error-capture.js') }}"></script>
@endif