#!/usr/bin/env python3
"""
NativePHP Error Sync Relay Server
Receives error payloads from mobile devices and formats them for AI agents.

Usage:
    python error-relay.py --port 9999 --output ~/agent-errors
    pip install -r requirements.txt  # for clipboard support
"""

import argparse
import base64
import json
import os
import re
import socket
import sys
from datetime import datetime
from pathlib import Path
from http.server import HTTPServer, BaseHTTPRequestHandler

# === FORCE UTF-8 ON WINDOWS ===
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')
# ===============================


class ErrorRelayHandler(BaseHTTPRequestHandler):
    output_dir = Path.home() / "agent-errors"
    
    def do_GET(self):
        if self.path == '/ping':
            self.send_response(200)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps({
                'status': 'ok',
                'service': 'NativePHP Error Sync Relay',
                'version': '1.2.0',
                'features': ['error-capture', 'screenshot-embed', 'markdown-export', 'clipboard-copy'],
            }, ensure_ascii=False).encode('utf-8'))
        elif self.path == '/latest':
            # Serve the latest error as JSON
            latest_file = self.output_dir / "latest.md"
            if latest_file.exists():
                self.send_response(200)
                self.send_header('Content-Type', 'text/markdown; charset=utf-8')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(latest_file.read_text(encoding='utf-8').encode('utf-8'))
            else:
                self.send_response(404)
                self.send_header('Content-Type', 'application/json; charset=utf-8')
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(json.dumps({
                    'error': 'No errors captured yet',
                }, ensure_ascii=False).encode('utf-8'))
        elif self.path.startswith('/screenshot/'):
            # Serve saved screenshots
            filename = self.path.split('/')[-1]
            screenshot_file = self.output_dir / filename
            if screenshot_file.exists() and screenshot_file.suffix in ['.png', '.jpg', '.jpeg']:
                content_type = 'image/png' if screenshot_file.suffix == '.png' else 'image/jpeg'
                self.send_response(200)
                self.send_header('Content-Type', content_type)
                self.send_header('Access-Control-Allow-Origin', '*')
                self.end_headers()
                self.wfile.write(screenshot_file.read_bytes())
            else:
                self.send_response(404)
                self.send_header('Content-Type', 'application/json; charset=utf-8')
                self.end_headers()
                self.wfile.write(json.dumps({
                    'error': 'Screenshot not found',
                }, ensure_ascii=False).encode('utf-8'))
        else:
            self.send_response(404)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps({
                'error': 'Not found',
                'endpoints': ['/ping', '/latest', '/error (POST)'],
            }, ensure_ascii=False).encode('utf-8'))
    
    def do_POST(self):
        if self.path != '/error':
            self.send_response(404)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Access-Control-Allow-Origin', '*')
            self.end_headers()
            self.wfile.write(json.dumps({
                'error': 'Use POST /error to submit error reports',
            }, ensure_ascii=False).encode('utf-8'))
            return
        
        content_length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_length)
        payload = json.loads(body)
        
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        trigger = payload.get('trigger', 'unknown')
        app_name = self.headers.get('X-App-Name', 'Unknown')
        error_msg = payload.get('error', 'Unknown error')[:120]
        
        # Ensure output directory exists
        self.output_dir.mkdir(parents=True, exist_ok=True)
        
        # =============================================
        # SAVE SCREENSHOT (if present)
        # =============================================
        screenshot_path = ""
        screenshot_data = payload.get('screenshot')
        
        if screenshot_data:
            try:
                # Handle data URI format: data:image/jpeg;base64,...
                if isinstance(screenshot_data, str) and screenshot_data.startswith('data:image'):
                    match = re.match(r'data:image/(\w+);base64,(.+)', screenshot_data)
                    if match:
                        img_format = match.group(1)
                        if img_format == 'jpeg':
                            img_format = 'jpg'
                        img_data = base64.b64decode(match.group(2))
                        
                        screenshot_file = self.output_dir / f"screenshot_{timestamp}.{img_format}"
                        screenshot_file.write_bytes(img_data)
                        screenshot_path = str(screenshot_file)
                        
                        print(f"   [SCREENSHOT] Saved: {screenshot_file.name} ({len(img_data)} bytes)")
                    else:
                        print(f"   [SCREENSHOT] Could not parse data URI")
                
                # Handle raw base64
                elif isinstance(screenshot_data, str) and len(screenshot_data) > 100:
                    try:
                        img_data = base64.b64decode(screenshot_data)
                        screenshot_file = self.output_dir / f"screenshot_{timestamp}.png"
                        screenshot_file.write_bytes(img_data)
                        screenshot_path = str(screenshot_file)
                        print(f"   [SCREENSHOT] Saved (raw base64): {screenshot_file.name}")
                    except:
                        # JSON fallback snapshot
                        screenshot_file = self.output_dir / f"snapshot_{timestamp}.json"
                        screenshot_file.write_text(screenshot_data, encoding='utf-8')
                        screenshot_path = str(screenshot_file)
                        print(f"   [SNAPSHOT] Saved DOM snapshot: {screenshot_file.name}")
                
                # Handle dict (JSON snapshot)
                elif isinstance(screenshot_data, dict):
                    screenshot_file = self.output_dir / f"snapshot_{timestamp}.json"
                    screenshot_file.write_text(
                        json.dumps(screenshot_data, indent=2, ensure_ascii=False),
                        encoding='utf-8'
                    )
                    screenshot_path = str(screenshot_file)
                    print(f"   [SNAPSHOT] Saved DOM snapshot: {screenshot_file.name}")
                    
            except Exception as e:
                print(f"   [SCREENSHOT] Error: {e}")
                screenshot_path = ""
        
        # =============================================
        # SAVE RAW JSON
        # =============================================
        json_payload = payload.copy()
        if 'screenshot' in json_payload and json_payload['screenshot']:
            json_payload['screenshot'] = f"[SAVED TO DISK: {screenshot_path}]" if screenshot_path else "[REMOVED]"
        
        raw_file = self.output_dir / f"error_{timestamp}.json"
        raw_file.write_text(
            json.dumps(json_payload, indent=2, ensure_ascii=False),
            encoding='utf-8'
        )
        
        # =============================================
        # FORMAT AND SAVE MARKDOWN (with embedded screenshot)
        # =============================================
        md = self.format_markdown(payload, timestamp, trigger, app_name, screenshot_path)
        latest_file = self.output_dir / "latest.md"
        latest_file.write_text(md, encoding='utf-8')
        
        # =============================================
        # TRY TO COPY TO CLIPBOARD
        # =============================================
        clipboard_status = ""
        try:
            import pyperclip
            pyperclip.copy(md)
            clipboard_status = "[OK] Copied to clipboard"
        except ImportError:
            clipboard_status = ""
        
        # =============================================
        # PRINT SUMMARY
        # =============================================
        print(f"\n{'='*60}")
        print(f"[PHONE] Error from {app_name}")
        print(f"   Time: {timestamp}")
        print(f"   Trigger: {trigger}")
        print(f"   Error: {error_msg}")
        print(f"   Route: {payload.get('route', 'unknown')}")
        print(f"   File: {payload.get('errorFile', 'N/A')}")
        if payload.get('errorLine'):
            print(f"   Line: {payload.get('errorLine')}")
        print(f"   PHP: {payload.get('app', {}).get('php', '?')} | Laravel: {payload.get('app', {}).get('laravel', '?')}")
        
        # Print collected data counts
        counts = []
        if payload.get('phpErrors'):
            counts.append(f"{len(payload['phpErrors'])} PHP errors")
        if payload.get('jsErrors'):
            counts.append(f"{len(payload['jsErrors'])} JS errors")
        if payload.get('userActions'):
            counts.append(f"{len(payload['userActions'])} user actions")
        if payload.get('networkRequests'):
            counts.append(f"{len(payload['networkRequests'])} network requests")
        if payload.get('consoleLogs'):
            counts.append(f"{len(payload['consoleLogs'])} console logs")
        if counts:
            print(f"   Data: {', '.join(counts)}")
        
        print(f"   JSON: {raw_file.name}")
        print(f"   Markdown: latest.md")
        if screenshot_path:
            print(f"   Screenshot: {Path(screenshot_path).name}")
        if clipboard_status:
            print(f"   {clipboard_status}")
        print(f"{'='*60}\n")
        
        # =============================================
        # SEND RESPONSE
        # =============================================
        response_data = {
            'status': 'received',
            'timestamp': timestamp,
            'file': str(latest_file),
        }
        if screenshot_path:
            response_data['screenshot'] = str(screenshot_path)
        
        self.send_response(200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(response_data, ensure_ascii=False).encode('utf-8'))
    
    def do_OPTIONS(self):
        self.send_response(200)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type, X-App-Name, X-Error-Sync')
        self.end_headers()
    
    def _get_screenshot_base64(self, screenshot_path):
        """Read a screenshot file and return it as a base64 data URI."""
        if not screenshot_path:
            return None
        
        try:
            sf = Path(screenshot_path)
            if not sf.exists():
                return None
            
            img_data = sf.read_bytes()
            ext = sf.suffix.lower()
            
            mime_map = {
                '.png': 'image/png',
                '.jpg': 'image/jpeg',
                '.jpeg': 'image/jpeg',
                '.gif': 'image/gif',
                '.webp': 'image/webp',
            }
            mime = mime_map.get(ext, 'image/png')
            
            b64 = base64.b64encode(img_data).decode('ascii')
            return f"data:{mime};base64,{b64}"
        except Exception:
            return None
    
    def format_markdown(self, p, timestamp, trigger, app_name, screenshot_path=""):
        """Format the error payload into an AI-agent-friendly markdown report."""
        
        lines = [
            f"# Bug Report: {app_name}",
            f"**Captured:** {timestamp}",
            f"**Trigger:** {trigger}",
            f"**Environment:** {p.get('app', {}).get('env', 'unknown')}",
            f"**PHP:** {p.get('app', {}).get('php', 'unknown')}",
            f"**Laravel:** {p.get('app', {}).get('laravel', 'unknown')}",
            "",
        ]
        
        # ==========================================
        # SCREENSHOT (EMBEDDED AS BASE64)
        # ==========================================
        if screenshot_path:
            b64_uri = self._get_screenshot_base64(screenshot_path)
            if b64_uri:
                lines.append("## Screenshot")
                lines.append(f"![Screenshot]({b64_uri})")
                lines.append("")
            else:
                # Fallback: just reference the file path
                lines.append("## Screenshot")
                lines.append(f"![Screenshot]({screenshot_path})")
                lines.append(f"*Screenshot saved at: `{screenshot_path}`*")
                lines.append("")
        
        # ==========================================
        # ERROR
        # ==========================================
        lines.append("## Error")
        lines.append("```")
        lines.append(p.get('error', 'No error message'))
        lines.append("```")
        lines.append("")
        
        if p.get('errorFile'):
            lines.append(f"**File:** `{p['errorFile']}`")
        if p.get('errorLine'):
            lines.append(f"**Line:** {p['errorLine']}")
        if p.get('errorType'):
            lines.append(f"**Type:** `{p['errorType']}`")
        if p.get('errorClass'):
            lines.append(f"**Exception Class:** `{p['errorClass']}`")
        lines.append("")
        
        # ==========================================
        # STACK TRACE
        # ==========================================
        if p.get('stackTrace'):
            lines.append("## Stack Trace")
            lines.append("```")
            stack = p.get('stackTrace', '')
            if len(stack) > 5000:
                stack = stack[:5000] + "\n... [truncated]"
            lines.append(stack)
            lines.append("```")
            lines.append("")
        
        # ==========================================
        # REQUEST INFO
        # ==========================================
        if p.get('route'):
            lines.append("## Request Details")
            lines.append("| Key | Value |")
            lines.append("|-----|-------|")
            lines.append(f"| URL | `{p.get('url', 'N/A')}` |")
            lines.append(f"| Route | `{p.get('route', 'N/A')}` |")
            if p.get('routeName'):
                lines.append(f"| Route Name | `{p['routeName']}` |")
            lines.append(f"| Method | `{p.get('method', 'GET')}` |")
            lines.append("")
        
        # ==========================================
        # USER ACTIONS
        # ==========================================
        if p.get('userActions'):
            actions = p['userActions'][-25:]
            if actions:
                lines.append("## Recent User Actions")
                lines.append("| Action | Details | Route |")
                lines.append("|--------|---------|-------|")
                for a in actions:
                    ctx = a.get('context', {})
                    desc = ctx.get('text') or ctx.get('dataAction') or a.get('action', '?')
                    route = a.get('route', '')
                    lines.append(f"| {a.get('action', '?')} | {desc} | `{route}` |")
                lines.append("")
        
        # ==========================================
        # NETWORK REQUESTS
        # ==========================================
        if p.get('networkRequests'):
            requests = p['networkRequests'][-20:]
            if requests:
                lines.append("## Recent Network Requests")
                lines.append("| Status | Method | URL | Duration |")
                lines.append("|--------|--------|-----|----------|")
                for r in requests:
                    status = r.get('status', '?')
                    icon = "OK" if status and status < 400 else "ERR"
                    url = r.get('url', '')[:80]
                    lines.append(
                        f"| {icon} {status} "
                        f"| `{r.get('method', 'GET')}` "
                        f"| `{url}` "
                        f"| {r.get('duration', '?')} |"
                    )
                lines.append("")
        
        # ==========================================
        # SESSION DATA
        # ==========================================
        if p.get('session'):
            lines.append("## Session Data")
            lines.append("```json")
            lines.append(json.dumps(p['session'], indent=2, ensure_ascii=False))
            lines.append("```")
            lines.append("")
        
        # ==========================================
        # DATABASE QUERIES
        # ==========================================
        if p.get('recentQueries'):
            queries = p['recentQueries'][:15]
            if queries:
                lines.append("## Recent Database Queries")
                lines.append("| Time | SQL |")
                lines.append("|------|-----|")
                for q in queries:
                    sql = q.get('sql', '')[:100]
                    time = q.get('time', '?')
                    lines.append(f"| {time} | `{sql}` |")
                lines.append("")
        
        # ==========================================
        # CONSOLE LOGS
        # ==========================================
        if p.get('consoleLogs'):
            logs = p['consoleLogs'][-25:]
            if logs:
                lines.append("## Console Logs")
                lines.append("```")
                for l in logs:
                    level = l.get('level', 'log').upper()
                    message = l.get('message', '')
                    lines.append(f"[{level}] {message}")
                lines.append("```")
                lines.append("")
        
        # ==========================================
        # PHP ERRORS (other recent)
        # ==========================================
        if p.get('phpErrors'):
            errors = p['phpErrors'][-10:]
            if len(errors) > 0:
                lines.append("## Recent PHP Errors")
                lines.append("| Type | Message | File | Line |")
                lines.append("|------|---------|------|------|")
                for e in errors:
                    msg = e.get('message', '')[:80]
                    file = e.get('file', '')[:40]
                    line = e.get('line', '')
                    etype = e.get('type', 'unknown')
                    lines.append(f"| `{etype}` | {msg} | `{file}` | {line} |")
                lines.append("")
        
        # ==========================================
        # INPUT DATA
        # ==========================================
        if p.get('input'):
            lines.append("## Request Input")
            lines.append("```json")
            lines.append(json.dumps(p['input'], indent=2, ensure_ascii=False))
            lines.append("```")
            lines.append("")
        
        # ==========================================
        # FOOTER
        # ==========================================
        lines.append("---")
        lines.append("")
        lines.append("**Instructions for AI Agent:**")
        lines.append("1. Analyze the error above with full context")
        if screenshot_path:
            lines.append("2. The screenshot is embedded above — examine it to see what the user was viewing")
        lines.append(f"3. The error occurred on route: `{p.get('route', 'unknown')}`")
        lines.append("4. Review the stack trace to identify the exact file and line")
        lines.append("5. Check recent user actions and network requests for context")
        lines.append("6. Provide a specific fix with file paths and code changes")
        lines.append("")
        lines.append(f"*Report generated by NativePHP Error Sync v1.2.0*")
        
        return "\n".join(lines)
    
    def log_message(self, format, *args):
        """Suppress default HTTP logs for cleaner console output."""
        pass


def get_local_ip():
    """Get the local network IP address."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.connect(('8.8.8.8', 80))
        ip = s.getsockname()[0]
        s.close()
        return ip
    except:
        return '127.0.0.1'


def main():
    parser = argparse.ArgumentParser(
        description='NativePHP Error Sync Relay Server',
        epilog='Receives errors from your mobile app and formats them for AI agents.'
    )
    parser.add_argument(
        '--port', 
        type=int, 
        default=9999, 
        help='Port to listen on (default: 9999)'
    )
    parser.add_argument(
        '--output', 
        type=str, 
        default=str(Path.home() / 'agent-errors'), 
        help='Output directory for error reports (default: ~/agent-errors)'
    )
    parser.add_argument(
        '--no-clipboard',
        action='store_true',
        help='Disable automatic clipboard copy'
    )
    parser.add_argument(
        '--no-embed',
        action='store_true',
        help='Disable base64 screenshot embedding (use file paths instead)'
    )
    args = parser.parse_args()
    
    # Expand user path
    output_path = Path(os.path.expanduser(args.output))
    ErrorRelayHandler.output_dir = output_path
    
    ip = get_local_ip()
    
    print(f"""
╔══════════════════════════════════════════════════════╗
║     NativePHP Error Sync Relay Server v1.2.0        ║
╠══════════════════════════════════════════════════════╣
║                                                     ║
║  Local:     http://localhost:{args.port}                     ║
║  Network:   http://{ip}:{args.port}              ║
║                                                     ║
║  Output:    {args.output}
║                                                     ║
║  Endpoints:                                         ║
║    GET  /ping      - Health check                   ║
║    GET  /latest    - View latest error report       ║
║    POST /error     - Submit error from phone        ║
║                                                     ║
║  Features:                                          ║
║    [x] Error capture & formatting                   ║
║    [x] Screenshot capture                           ║
║    [x] Base64 screenshot embedding                  ║
║    [x] Markdown export for AI agents                ║
║    [x] Clipboard auto-copy                          ║
║                                                     ║
║  Waiting for errors from your phone...              ║
║  Press Ctrl+C to stop                               ║
║                                                     ║
╚══════════════════════════════════════════════════════╝
""")
    
    # Start server
    server = HTTPServer(('0.0.0.0', args.port), ErrorRelayHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[OK] Relay server stopped")
        server.server_close()


if __name__ == '__main__':
    main()