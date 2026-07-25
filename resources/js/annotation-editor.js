(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.ErrorSyncAnnotationEditor = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    const COLORS = ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#ffffff', '#111827'];

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function canvasPoint(event, canvas) {
        const rect = canvas.getBoundingClientRect();
        return {
            x: clamp((event.clientX - rect.left) * (canvas.width / rect.width), 0, canvas.width),
            y: clamp((event.clientY - rect.top) * (canvas.height / rect.height), 0, canvas.height),
        };
    }

    function arrowHead(from, to, size) {
        const angle = Math.atan2(to.y - from.y, to.x - from.x);
        return [
            {
                x: to.x - size * Math.cos(angle - Math.PI / 6),
                y: to.y - size * Math.sin(angle - Math.PI / 6),
            },
            {
                x: to.x - size * Math.cos(angle + Math.PI / 6),
                y: to.y - size * Math.sin(angle + Math.PI / 6),
            },
        ];
    }

    function createHistory() {
        let actions = [];
        let undoStack = [];
        let redoStack = [];
        return {
            add(action) {
                undoStack.push(actions);
                actions = actions.concat(action);
                redoStack = [];
            },
            undo() {
                if (undoStack.length) {
                    redoStack.push(actions);
                    actions = undoStack.pop();
                }
            },
            redo() {
                if (redoStack.length) {
                    undoStack.push(actions);
                    actions = redoStack.pop();
                }
            },
            clear() {
                if (actions.length) {
                    undoStack.push(actions);
                    actions = [];
                    redoStack = [];
                }
            },
            get actions() { return actions.slice(); },
            get canUndo() { return undoStack.length > 0; },
            get canRedo() { return redoStack.length > 0; },
        };
    }

    function button(label, title, onClick, options = {}) {
        const element = document.createElement('button');
        element.type = 'button';
        element.textContent = label;
        element.title = title;
        element.setAttribute('aria-label', title);
        element.className = `error-sync-editor-button${options.compact ? ' is-compact' : ''}${options.variant ? ` is-${options.variant}` : ''}`;
        element.addEventListener('click', onClick);
        return element;
    }

    function loadImage(dataUri) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error('Could not load captured screenshot into editor'));
            image.src = dataUri;
        });
    }

    function drawAction(ctx, action) {
        ctx.save();
        ctx.strokeStyle = action.color;
        ctx.fillStyle = action.color;
        ctx.lineWidth = action.width;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        if (action.type === 'pen' || action.type === 'highlight') {
            ctx.globalAlpha = action.type === 'highlight' ? 0.32 : 1;
            ctx.lineWidth = action.type === 'highlight' ? action.width * 4 : action.width;
            ctx.beginPath();
            action.points.forEach((point, index) => {
                if (index === 0) ctx.moveTo(point.x, point.y);
                else ctx.lineTo(point.x, point.y);
            });
            ctx.stroke();
        } else if (action.type === 'rectangle') {
            ctx.strokeRect(action.start.x, action.start.y, action.end.x - action.start.x, action.end.y - action.start.y);
        } else if (action.type === 'arrow') {
            const head = arrowHead(action.start, action.end, Math.max(12, action.width * 4));
            ctx.beginPath();
            ctx.moveTo(action.start.x, action.start.y);
            ctx.lineTo(action.end.x, action.end.y);
            ctx.moveTo(action.end.x, action.end.y);
            ctx.lineTo(head[0].x, head[0].y);
            ctx.moveTo(action.end.x, action.end.y);
            ctx.lineTo(head[1].x, head[1].y);
            ctx.stroke();
        } else if (action.type === 'text') {
            ctx.font = `700 ${Math.max(18, action.width * 5)}px system-ui,sans-serif`;
            ctx.textBaseline = 'top';
            ctx.lineWidth = Math.max(2, action.width / 2);
            ctx.strokeStyle = action.color === '#111827' ? '#ffffff' : '#111827';
            ctx.strokeText(action.text, action.start.x, action.start.y);
            ctx.fillText(action.text, action.start.x, action.start.y);
        } else if (action.type === 'batch') {
            action.actions.forEach((item) => drawAction(ctx, item));
        }
        ctx.restore();
    }

    async function open(dataUri, options = {}) {
        if (typeof document === 'undefined') throw new Error('Annotation editor requires a browser document');
        const image = await loadImage(dataUri);
        const jpegQuality = clamp(Number(options.quality) || 0.72, 0.1, 0.95);
        const history = createHistory();
        let tool = 'pen';
        let color = COLORS[0];
        let width = 5;
        let active = null;
        let previousOverflow = document.body.style.overflow;

        const overlay = document.createElement('div');
        overlay.id = 'error-sync-annotation-editor';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Annotate screenshot');
        overlay.style.cssText = `
            position:fixed;inset:0;width:100vw;max-width:100vw;overflow:hidden;z-index:2147483647;background:#080d18;color:#f8fafc;
            display:flex;flex-direction:column;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom);
            box-sizing:border-box;overscroll-behavior:none;
        `;

        const styles = document.createElement('style');
        styles.textContent = `
            #error-sync-annotation-editor * { box-sizing: border-box; }
            #error-sync-annotation-editor .error-sync-editor-button {
                min-width: 54px; height: 40px; padding: 0 13px; border: 1px solid #334155;
                border-radius: 10px; background: #172033; color: #dbeafe;
                font: 650 13px/1 system-ui,sans-serif; letter-spacing: .01em;
                touch-action: manipulation; cursor: pointer; white-space: nowrap;
                transition: background .16s ease,border-color .16s ease,transform .12s ease,box-shadow .16s ease;
            }
            #error-sync-annotation-editor .error-sync-editor-button:hover { background:#22304a;border-color:#4b6385; }
            #error-sync-annotation-editor .error-sync-editor-button:active { transform:scale(.97); }
            #error-sync-annotation-editor .error-sync-editor-button:focus-visible { outline:3px solid rgba(96,165,250,.5);outline-offset:2px; }
            #error-sync-annotation-editor .error-sync-editor-button:disabled { cursor:default; }
            #error-sync-annotation-editor .error-sync-editor-button.is-compact { min-width:40px;padding:0 10px; }
            #error-sync-annotation-editor .error-sync-editor-button.is-primary { background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 6px 18px rgba(37,99,235,.3); }
            #error-sync-annotation-editor .error-sync-editor-button.is-success { background:#16a34a;border-color:#22c55e;color:#fff;box-shadow:0 6px 18px rgba(22,163,74,.28); }
            #error-sync-annotation-editor .error-sync-editor-button.is-danger { background:transparent;border-color:#475569;color:#cbd5e1; }
            #error-sync-annotation-editor .error-sync-editor-button.is-secondary { background:#1e293b;color:#e2e8f0; }
            #error-sync-annotation-editor .error-sync-tool-group {
                display:flex;align-items:center;gap:6px;padding:5px;border:1px solid #26344c;
                border-radius:13px;background:rgba(15,23,42,.82);box-shadow:inset 0 1px rgba(255,255,255,.025);
            }
            #error-sync-annotation-editor .error-sync-toolbar-label {
                color:#7f91aa;font:700 10px/1 system-ui,sans-serif;text-transform:uppercase;
                letter-spacing:.09em;padding:0 3px;white-space:nowrap;
            }
            #error-sync-annotation-editor .error-sync-editor-toolbar::-webkit-scrollbar { display:none; }
            @media (max-width: 640px) {
                #error-sync-annotation-editor .error-sync-editor-button { height:42px;padding:0 11px; }
                #error-sync-annotation-editor .error-sync-desktop-only { display:none; }
                #error-sync-annotation-editor .error-sync-editor-header { flex-wrap:wrap;padding:10px 12px; }
                #error-sync-annotation-editor .error-sync-editor-title { width:100%;text-align:center; }
                #error-sync-annotation-editor .error-sync-editor-header-actions { width:100%;display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr)); }
                #error-sync-annotation-editor .error-sync-editor-header-actions .error-sync-editor-button { width:100%; }
            }
        `;
        overlay.appendChild(styles);

        const header = document.createElement('div');
        header.className = 'error-sync-editor-header';
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-width:0;padding:12px 16px;background:rgba(15,23,42,.96);border-bottom:1px solid #26344c;box-shadow:0 8px 24px rgba(0,0,0,.2);flex:0 0 auto;';
        const titleBlock = document.createElement('div');
        titleBlock.className = 'error-sync-editor-title';
        titleBlock.style.cssText = 'display:flex;flex-direction:column;min-width:0;';
        const title = document.createElement('strong');
        title.textContent = 'Annotate screenshot';
        title.style.cssText = 'font-size:15px;letter-spacing:-.01em;color:#f8fafc;';
        const subtitle = document.createElement('span');
        subtitle.className = 'error-sync-desktop-only';
        subtitle.textContent = 'Highlight the issue before sending it to your coding agent';
        subtitle.style.cssText = 'margin-top:3px;color:#8292aa;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
        titleBlock.append(title, subtitle);
        const headerActions = document.createElement('div');
        headerActions.className = 'error-sync-editor-header-actions';
        headerActions.style.cssText = 'display:flex;gap:8px;flex:0 0 auto;';
        header.append(titleBlock, headerActions);

        const stage = document.createElement('div');
        stage.style.cssText = 'position:relative;width:100%;min-width:0;flex:1 1 auto;min-height:0;display:flex;align-items:center;justify-content:center;padding:18px;background-color:#080d18;background-image:linear-gradient(45deg,#0d1524 25%,transparent 25%),linear-gradient(-45deg,#0d1524 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#0d1524 75%),linear-gradient(-45deg,transparent 75%,#0d1524 75%);background-size:24px 24px;background-position:0 0,0 12px,12px -12px,-12px 0;overflow:hidden;';
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth || image.width;
        canvas.height = image.naturalHeight || image.height;
        canvas.style.cssText = 'display:block;max-width:100%;max-height:100%;width:auto;height:auto;touch-action:none;background:white;border:1px solid rgba(148,163,184,.25);border-radius:8px;box-shadow:0 18px 50px rgba(0,0,0,.52),0 0 0 1px rgba(255,255,255,.03);';
        stage.appendChild(canvas);

        const toolbar = document.createElement('div');
        toolbar.className = 'error-sync-editor-toolbar';
        toolbar.style.cssText = 'display:flex;align-items:center;gap:9px;width:100%;min-width:0;max-width:100vw;padding:10px 12px;background:rgba(15,23,42,.98);border-top:1px solid #26344c;box-shadow:0 -10px 30px rgba(0,0,0,.22);overflow-x:auto;flex:0 0 auto;scrollbar-width:none;';

        const ctx = canvas.getContext('2d');
        const redraw = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
            history.actions.forEach((action) => drawAction(ctx, action));
            if (active) drawAction(ctx, active);
            undoButton.disabled = !history.canUndo;
            redoButton.disabled = !history.canRedo;
            undoButton.style.opacity = undoButton.disabled ? '.45' : '1';
            redoButton.style.opacity = redoButton.disabled ? '.45' : '1';
        };

        const toolButtons = new Map();
        const drawingGroup = document.createElement('div');
        drawingGroup.className = 'error-sync-tool-group';
        const drawingLabel = document.createElement('span');
        drawingLabel.className = 'error-sync-toolbar-label error-sync-desktop-only';
        drawingLabel.textContent = 'Tools';
        drawingGroup.appendChild(drawingLabel);
        const selectTool = (next) => {
            tool = next;
            toolButtons.forEach((element, name) => {
                element.classList.toggle('is-primary', name === tool);
                element.setAttribute('aria-pressed', name === tool ? 'true' : 'false');
            });
        };
        [['pen', 'Pen'], ['highlight', 'Highlight'], ['arrow', 'Arrow'], ['rectangle', 'Box'], ['text', 'Text']].forEach(([name, label]) => {
            const element = button(label, label, () => selectTool(name));
            toolButtons.set(name, element);
            drawingGroup.appendChild(element);
        });
        toolbar.appendChild(drawingGroup);

        const colorGroup = document.createElement('div');
        colorGroup.className = 'error-sync-tool-group';
        const colorLabel = document.createElement('span');
        colorLabel.className = 'error-sync-toolbar-label error-sync-desktop-only';
        colorLabel.textContent = 'Color';
        colorGroup.appendChild(colorLabel);
        COLORS.forEach((value) => {
            const swatch = button('', `Color ${value}`, () => {
                color = value;
                Array.from(colorGroup.querySelectorAll('button')).forEach((item) => item.style.outline = 'none');
                swatch.style.outline = '3px solid #e2e8f0';
            }, { compact: true });
            swatch.style.minWidth = '32px';
            swatch.style.width = '32px';
            swatch.style.background = value;
            swatch.style.borderColor = value === '#ffffff' ? '#64748b' : value;
            if (value === color) swatch.style.outline = '3px solid #e2e8f0';
            colorGroup.appendChild(swatch);
        });
        toolbar.appendChild(colorGroup);

        const brushGroup = document.createElement('div');
        brushGroup.className = 'error-sync-tool-group';
        const brushLabel = document.createElement('span');
        brushLabel.className = 'error-sync-toolbar-label error-sync-desktop-only';
        brushLabel.textContent = 'Size';
        brushGroup.appendChild(brushLabel);
        const size = document.createElement('input');
        size.type = 'range';
        size.min = '2';
        size.max = '16';
        size.value = String(width);
        size.title = 'Brush size';
        size.setAttribute('aria-label', 'Brush size');
        size.style.cssText = 'width:92px;height:32px;accent-color:#3b82f6;';
        size.addEventListener('input', () => { width = Number(size.value); });
        brushGroup.appendChild(size);
        toolbar.appendChild(brushGroup);

        const historyGroup = document.createElement('div');
        historyGroup.className = 'error-sync-tool-group';
        const undoButton = button('Undo', 'Undo', () => { history.undo(); redraw(); });
        const redoButton = button('Redo', 'Redo', () => { history.redo(); redraw(); });
        const clearButton = button('Clear', 'Clear all marks', () => { history.clear(); redraw(); });
        historyGroup.append(undoButton, redoButton, clearButton);
        toolbar.appendChild(historyGroup);

        const finish = (result) => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.body.style.overflow = previousOverflow;
            overlay.remove();
            resolver(result);
        };
        let resolver;
        const resultPromise = new Promise((resolve) => { resolver = resolve; });
        headerActions.append(
            button('Cancel', 'Cancel report', () => finish({ action: 'cancel' }), { variant: 'danger' }),
            button('Retake', 'Retake screenshot', () => finish({ action: 'retake' }), { variant: 'secondary' }),
            button('Send', 'Send annotated screenshot', () => {
                redraw();
                finish({
                    action: 'send',
                    image: history.actions.length ? canvas.toDataURL('image/jpeg', jpegQuality) : dataUri,
                    annotations: history.actions.length,
                });
            }, { variant: 'success' })
        );

        const begin = (event) => {
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            event.preventDefault();
            canvas.setPointerCapture?.(event.pointerId);
            const point = canvasPoint(event, canvas);
            if (tool === 'text') {
                const text = window.prompt('Annotation text:');
                if (text && text.trim()) history.add({ type: 'text', start: point, text: text.trim().slice(0, 120), color, width });
                redraw();
                return;
            }
            active = tool === 'pen' || tool === 'highlight'
                ? { type: tool, points: [point], color, width }
                : { type: tool, start: point, end: point, color, width };
            redraw();
        };
        const move = (event) => {
            if (!active) return;
            event.preventDefault();
            const point = canvasPoint(event, canvas);
            if (active.points) active.points.push(point);
            else active.end = point;
            redraw();
        };
        const end = (event) => {
            if (!active) return;
            event.preventDefault();
            if (!active.points) active.end = canvasPoint(event, canvas);
            if ((active.points && active.points.length > 1)
                || (!active.points && (active.start.x !== active.end.x || active.start.y !== active.end.y))) {
                history.add(active);
            }
            active = null;
            redraw();
        };
        canvas.addEventListener('pointerdown', begin);
        canvas.addEventListener('pointermove', move);
        canvas.addEventListener('pointerup', end);
        canvas.addEventListener('pointercancel', () => { active = null; redraw(); });

        const onKeyDown = (event) => {
            if (event.key === 'Escape') finish({ action: 'cancel' });
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
                event.preventDefault();
                event.shiftKey ? history.redo() : history.undo();
                redraw();
            }
        };
        document.addEventListener('keydown', onKeyDown, true);
        overlay.append(header, stage, toolbar);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        selectTool(tool);
        redraw();
        return resultPromise;
    }

    return { open, clamp, canvasPoint, arrowHead, createHistory, drawAction };
});
