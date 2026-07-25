// resources/js/error-capture.js
(function() {
    'use strict';

    let screenshotDiagnostic = 'not attempted';

    const ENDPOINTS = {
        jsError: '/_error-sync/js-error',
        network: '/_error-sync/network',
        action: '/_error-sync/action',
        console: '/_error-sync/console',
        capture: '/_error-sync/capture',
        screenshot: '/_error-sync/screenshot',
    };

    // ==========================================
    // 0. SCREENSHOT CAPTURE (loaded first)
    // ==========================================
    
    /**
     * Capture the current WebView as a base64 image.
     * Uses html2canvas if available, falls back to a manual DOM snapshot.
     */
    async function captureScreenshot() {
        screenshotDiagnostic = 'capture started';
        console.info('[ErrorSync] Screenshot capture requested', {
            html2canvas: typeof window.html2canvas === 'function',
            nativeBridge: typeof window.NativePHP?.captureScreenshot === 'function',
        });

        // Method 1: html2canvas (best quality)
        if (window.html2canvas) {
            try {
                const canvas = await html2canvas(document.body, {
                    useCORS: true,
                    // A tainted canvas cannot be exported with toDataURL. Skip
                    // inaccessible cross-origin images instead of losing the
                    // entire screenshot.
                    allowTaint: false,
                    scale: 0.5,           // Half resolution for smaller payload
                    logging: false,
                    backgroundColor: '#ffffff',
                });
                const image = canvas.toDataURL('image/jpeg', 0.6);
                screenshotDiagnostic = `html2canvas captured ${image.length} characters`;
                return image; // JPEG for smaller size
            } catch (e) {
                screenshotDiagnostic = `html2canvas failed: ${e.message}`;
                console.warn('[ErrorSync] html2canvas failed:', e.message);
            }
        }

        // Method 2: NativePHP native snapshot (if available)
        if (window.NativePHP?.captureScreenshot) {
            try {
                const image = await window.NativePHP.captureScreenshot();
                screenshotDiagnostic = image ? 'NativePHP bridge captured screenshot' : 'NativePHP bridge returned no image';
                return image;
            } catch (e) {
                screenshotDiagnostic = `NativePHP bridge failed: ${e.message}`;
                console.warn('[ErrorSync] Native snapshot failed:', e.message);
            }
        }

        screenshotDiagnostic = `no capture provider (html2canvas: ${typeof window.html2canvas})`;
        return null;
    }

    // ==========================================
    // 1. JS Error Capture
    // ==========================================
    window.addEventListener('error', (e) => {
        post(ENDPOINTS.jsError, {
            message: e.message,
            source: e.filename,
            lineno: e.lineno,
            colno: e.colno,
            stack: e.error?.stack || '',
        });
    });

    window.addEventListener('unhandledrejection', (e) => {
        post(ENDPOINTS.jsError, {
            message: e.reason?.message || String(e.reason),
            stack: e.reason?.stack || '',
        });
    });

    // ==========================================
    // 2. Console Interception
    // ==========================================
    ['log', 'warn', 'error', 'debug', 'info'].forEach((level) => {
        const original = console[level];
        console[level] = function(...args) {
            original.apply(console, args);
            post(ENDPOINTS.console, {
                level,
                message: args.map(a => {
                    try { 
                        if (typeof a === 'object') return JSON.stringify(a).substring(0, 300);
                        return String(a).substring(0, 300);
                    }
                    catch { return String(a).substring(0, 300); }
                }).join(' '),
            });
        };
    });

    // ==========================================
    // 3. Network Interception
    // ==========================================
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        const start = performance.now();
        const url = typeof args[0] === 'string' ? args[0] : args[0].url;
        const method = args[1]?.method || 'GET';

        return originalFetch.apply(this, args).then(r => {
            post(ENDPOINTS.network, { 
                method, url, 
                status: r.status, 
                duration: Math.round(performance.now() - start) 
            });
            return r;
        }).catch(e => {
            post(ENDPOINTS.network, { 
                method, url, 
                status: 0, 
                duration: Math.round(performance.now() - start), 
                error: e.message 
            });
            throw e;
        });
    };

    // Also intercept XMLHttpRequest
    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url) {
        this._errorSync = { method, url, startTime: performance.now() };
        return originalXHROpen.apply(this, arguments);
    };
    
    XMLHttpRequest.prototype.send = function() {
        this.addEventListener('loadend', () => {
            if (this._errorSync) {
                post(ENDPOINTS.network, {
                    method: this._errorSync.method,
                    url: this._errorSync.url,
                    status: this.status,
                    duration: Math.round(performance.now() - this._errorSync.startTime),
                });
            }
        });
        return originalXHRSend.apply(this, arguments);
    };

    // ==========================================
    // 4. User Action Tracking
    // ==========================================
    document.addEventListener('click', (e) => {
        const target = e.target.closest('button, a, input[type="submit"], [data-action], form');
        if (target) {
            post(ENDPOINTS.action, {
                action: 'click',
                context: {
                    tag: target.tagName.toLowerCase(),
                    text: target.textContent?.trim().substring(0, 50),
                    id: target.id,
                    className: target.className?.substring(0, 100),
                    dataAction: target.dataset?.action,
                    href: target.href,
                    coordinates: { x: e.clientX, y: e.clientY },
                },
            });
        }
    });

    // Track form submissions
    document.addEventListener('submit', (e) => {
        post(ENDPOINTS.action, {
            action: 'form_submit',
            context: {
                formId: e.target.id,
                formAction: e.target.action,
                inputs: Array.from(e.target.elements)
                    .filter(el => el.name)
                    .map(el => ({ name: el.name, type: el.type })),
            },
        });
    });

    // Track navigation
    window.addEventListener('popstate', () => {
        post(ENDPOINTS.action, {
            action: 'navigation',
            context: { url: window.location.href, type: 'popstate' },
        });
    });

    // ==========================================
    // 5. Trigger Full Capture (with screenshot!)
    // ==========================================
    
    // Shake (NativePHP)
    if (window.NativePHP?.onShake) {
        window.NativePHP.onShake(() => triggerCapture('shake'));
    }

    // 3-finger triple tap (mobile fallback)
    let tapCount = 0, tapTimer;
    document.addEventListener('touchstart', (e) => {
        if (e.touches.length >= 3) {
            tapCount++;
            clearTimeout(tapTimer);
            tapTimer = setTimeout(() => tapCount = 0, 1000);
            if (tapCount >= 3) { 
                tapCount = 0; 
                triggerCapture('triple_tap'); 
            }
        }
    });

    // Keyboard shortcut (desktop)
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.shiftKey && e.key === 'E') {
            e.preventDefault();
            triggerCapture('keyboard_shortcut');
        }
    });

    /**
     * Main capture function — now with screenshot!
     */
    async function triggerCapture(trigger) {
        flash('📸 Capturing error + screenshot...', 'info');
        
        try {
            // Capture screenshot FIRST
            let screenshot = null;
            if (isScreenshotEnabled()) {
                screenshot = await captureScreenshot();
                if (screenshot) {
                    flash('📸 Screenshot captured!', 'info');
                } else {
                    flash('⚠️ Screenshot library unavailable; sending error details only', 'warning');
                }
            }
            
            // Send everything to backend
            const response = await fetch(ENDPOINTS.capture, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ 
                    trigger,
                    screenshot: screenshot,
                    screenshotDiagnostic,
                }),
            });
            
            if (!response.ok) {
                throw new Error(`Capture request failed (${response.status})`);
            }

            const result = await response.json();

            console.info('[ErrorSync] Capture completed', {
                screenshotCaptured: Boolean(screenshot),
                relayAccepted: Boolean(result.success),
            });
            
            if (result.success) {
                flash('✅ Error synced to laptop!', 'success');
                vibrate();
            } else {
                flash('⚠️ ' + (result.error || 'Saved locally'), 'warning');
            }
        } catch (e) {
            flash('❌ Failed: ' + e.message, 'error');
        }
    }

    function isScreenshotEnabled() {
        // Check config passed from backend (set in Blade template)
        return window.__errorSyncConfig?.screenshot !== false;
    }

    // Expose globally for the button
    window.__errorSyncCapture = triggerCapture;
    window.__errorSyncScreenshot = captureScreenshot; // Also expose for direct use

    // ==========================================
    // 6. Helpers
    // ==========================================
    function post(url, data) {
        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify(data),
        }).catch(() => {}); // Silent fail
    }

    function flash(msg, type = 'info') {
        const colors = { 
            info: '#3b82f6', 
            success: '#10b981', 
            warning: '#f59e0b', 
            error: '#ef4444' 
        };

        let container = document.getElementById('error-sync-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'error-sync-toast-container';
            container.style.cssText = `
                position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
                z-index: 99999; display: flex; flex-direction: column;
                align-items: center; gap: 10px; width: min(92vw, 520px);
                pointer-events: none;
            `;
            document.body.appendChild(container);
        }

        const el = document.createElement('div');
        el.style.cssText = `
            background: ${colors[type] || colors.info}; color: white;
            padding: 12px 24px; border-radius: 8px;
            font-family: system-ui, sans-serif; font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            transition: opacity 0.3s ease, transform 0.3s ease;
            max-width: 100%; box-sizing: border-box; text-align: center;
        `;
        el.textContent = msg;
        container.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(-6px)';
            setTimeout(() => {
                el.remove();
                if (!container.children.length) {
                    container.remove();
                }
            }, 300);
        }, 2500);
    }

    function vibrate() {
        navigator.vibrate?.([50, 50, 50]);
    }

    // Initialize
    console.log('🔍 ErrorSync ready — shake, triple-tap, or press Ctrl+Shift+E');
})();
