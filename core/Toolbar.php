<?php

// Debug toolbar: a fixed panel appended to HTML responses when APP_DEBUG is
// on. Collects timing, memory, the matched route, the query log, session
// keys, log lines and file counts, and renders them with inline CSS/JS that
// carries the request's CSP nonce. Function wrappers live in
// core/modules/90-toolbar.php; send_response() calls toolbar_inject().

class Toolbar
{
    private static bool $disabled = false;

    // Tests run under the CLI SAPI, where the toolbar is off unless forced.
    private static bool $forced = false;

    public static function disable(): void
    {
        self::$disabled = true;
    }

    public static function enable(bool $force = false): void
    {
        self::$disabled = false;
        self::$forced = $force;
    }

    // Cheap checks first so a disabled toolbar costs a few comparisons.
    public static function shouldRun(Response $response): bool
    {
        if (self::$disabled || !config('app.toolbar', true)) {
            return false;
        }

        // Only debug web requests; forced on by tests, which run under the CLI.
        if (!self::$forced && (!APP_DEBUG || PHP_SAPI === 'cli')) {
            return false;
        }

        if (($_GET['_toolbar'] ?? null) === '0') {
            return false;
        }

        if ($response->status < 200 || $response->status >= 400 || isset($response->headers['Location'])) {
            return false;
        }

        $type = strtolower((string) ($response->headers['Content-Type'] ?? 'text/html'));

        if (!str_starts_with($type, 'text/html')) {
            return false;
        }

        return str_contains($response->body, '</body>');
    }

    public static function inject(Response $response): void
    {
        if (!self::shouldRun($response)) {
            return;
        }

        $position = strrpos($response->body, '</body>');
        $html = self::render(self::collect($response));

        $response->body = substr($response->body, 0, $position) . $html . substr($response->body, $position);
    }

    public static function collect(Response $response): array
    {
        $route = current_route();
        $action = $route['action'] ?? null;
        $slow = (float) config('database.slow_query_ms', 0);
        $queries = [];

        foreach (Database::queryLog() as $query) {
            $queries[] = [
                'sql' => $query['sql'],
                'bindings' => $query['bindings'],
                'time' => (float) $query['time'],
                'connection' => $query['connection'],
                'slow' => $slow > 0 && $query['time'] >= $slow,
            ];
        }

        return [
            'time_ms' => round((microtime(true) - APP_START) * 1000, 2),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'method' => request_method(),
            'path' => request_path(),
            'status' => $response->status,
            'route' => $route === null ? null : [
                'name' => $route['name'] ?? null,
                'path' => $route['path'] ?? '',
                'action' => is_string($action) ? $action : (is_array($action) ? implode('@', $action) : 'Closure'),
                'middleware' => array_merge(global_middleware(), $route['middleware'] ?? []),
                'parameters' => $route['parameters'] ?? [],
            ],
            'queries' => $queries,
            'query_time_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'slow_ms' => $slow,
            'session' => self::sessionSummary(),
            'auth_id' => auth_id(),
            'files' => count(get_included_files()),
            'logs' => function_exists('log_buffer') ? log_buffer() : [],
            'php' => PHP_VERSION,
        ];
    }

    // Session keys with truncated values; secrets are never shown. Reads the
    // session only if one is already active — the toolbar must not start one.
    private static function sessionSummary(): array
    {
        if (!session_started()) {
            return [];
        }

        $summary = [];

        foreach ($_SESSION as $key => $value) {
            $key = (string) $key;

            if (preg_match('/token|csrf|password|secret/i', $key)) {
                $summary[$key] = '(hidden)';

                continue;
            }

            $summary[$key] = str_limit(
                is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                60
            );
        }

        return $summary;
    }

    public static function render(array $data): string
    {
        $e = fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $nonce = $e(csp_nonce());
        $queries = count($data['queries']);
        $slowCount = count(array_filter($data['queries'], fn ($q) => $q['slow']));
        $route = $data['route'];

        $chips = [
            ['Time', $data['time_ms'] . ' ms'],
            ['Memory', $data['memory_mb'] . ' MB'],
            ['Queries', $queries . ($data['query_time_ms'] > 0 ? ' · ' . $data['query_time_ms'] . ' ms' : '') . ($slowCount > 0 ? ' · ' . $slowCount . ' slow' : '')],
            ['Route', $route === null ? '(none)' : ($route['name'] ?? $route['path'])],
            ['Request', $data['method'] . ' ' . $data['path'] . ' → ' . $data['status']],
            ['User', $data['auth_id'] === null ? 'guest' : '#' . $data['auth_id']],
            ['Files', (string) $data['files']],
            ['Logs', (string) count($data['logs'])],
        ];

        $bar = '';

        foreach ($chips as [$label, $value]) {
            $bar .= '<span class="cf-chip"><b>' . $e($label) . '</b> ' . $e($value) . '</span>';
        }

        // Panels ----------------------------------------------------------

        $request = '<table><tr><th>Method</th><td>' . $e($data['method']) . '</td></tr>'
            . '<tr><th>Path</th><td>' . $e($data['path']) . '</td></tr>'
            . '<tr><th>Status</th><td>' . $e($data['status']) . '</td></tr>';

        if ($route !== null) {
            $request .= '<tr><th>Route</th><td>' . $e($route['path']) . ($route['name'] !== null ? ' (' . $e($route['name']) . ')' : '') . '</td></tr>'
                . '<tr><th>Action</th><td>' . $e($route['action']) . '</td></tr>'
                . '<tr><th>Middleware</th><td>' . $e(implode(', ', $route['middleware']) ?: '—') . '</td></tr>'
                . '<tr><th>Parameters</th><td>' . $e($route['parameters'] === [] ? '—' : json_encode($route['parameters'], JSON_UNESCAPED_SLASHES)) . '</td></tr>';
        }

        $request .= '<tr><th>PHP</th><td>' . $e($data['php']) . '</td></tr>'
            . '<tr><th>Included files</th><td>' . $e($data['files']) . '</td></tr></table>';

        if ($queries === 0) {
            $queryPanel = '<p class="cf-muted">No queries were logged.</p>';
        } else {
            $queryPanel = '<table><tr><th>#</th><th>Time</th><th>SQL</th><th>Bindings</th></tr>';

            foreach ($data['queries'] as $i => $query) {
                $queryPanel .= '<tr' . ($query['slow'] ? ' class="cf-slow"' : '') . '>'
                    . '<td>' . ($i + 1) . '</td>'
                    . '<td>' . $e($query['time']) . ' ms' . ($query['slow'] ? ' ⚠' : '') . '</td>'
                    . '<td><code>' . $e($query['sql']) . '</code></td>'
                    . '<td><code>' . $e($query['bindings'] === [] ? '—' : json_encode($query['bindings'], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) . '</code></td>'
                    . '</tr>';
            }

            $queryPanel .= '</table>';

            if ($data['slow_ms'] > 0) {
                $queryPanel .= '<p class="cf-muted">Slow threshold: ' . $e($data['slow_ms']) . ' ms (DB_SLOW_QUERY_MS).</p>';
            }
        }

        if ($data['session'] === []) {
            $sessionPanel = '<p class="cf-muted">No active session.</p>';
        } else {
            $sessionPanel = '<table>';

            foreach ($data['session'] as $key => $value) {
                $sessionPanel .= '<tr><th>' . $e($key) . '</th><td><code>' . $e($value) . '</code></td></tr>';
            }

            $sessionPanel .= '</table>';
        }

        if ($data['logs'] === []) {
            $logPanel = '<p class="cf-muted">Nothing was logged during this request.</p>';
        } else {
            $logPanel = '<table>';

            foreach ($data['logs'] as $entry) {
                $logPanel .= '<tr><th>' . $e(strtoupper($entry['level'])) . '</th><td>' . $e($entry['message'])
                    . ($entry['context'] !== '' ? ' <code>' . $e($entry['context']) . '</code>' : '') . '</td></tr>';
            }

            $logPanel .= '</table>';
        }

        $tabs = [
            ['request', 'Request', $request],
            ['queries', 'Queries (' . $queries . ')', $queryPanel],
            ['session', 'Session (' . count($data['session']) . ')', $sessionPanel],
            ['logs', 'Logs (' . count($data['logs']) . ')', $logPanel],
        ];

        $tabButtons = '';
        $tabPanels = '';

        foreach ($tabs as $i => [$id, $label, $content]) {
            $active = $i === 0 ? ' cf-active' : '';
            $tabButtons .= '<button type="button" class="cf-tab' . $active . '" data-cf-tab="' . $id . '">' . $e($label) . '</button>';
            $tabPanels .= '<div class="cf-panel' . $active . '" data-cf-panel="' . $id . '">' . $content . '</div>';
        }

        return <<<HTML

        <!-- ComfreePHP debug toolbar (APP_DEBUG=true; append ?_toolbar=0 or set app.toolbar=false to hide) -->
        <style nonce="$nonce">
        #cf-toolbar{position:fixed;left:0;right:0;bottom:0;z-index:2147483000;font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:#e2e8f0;background:#0f172a;border-top:2px solid #6366f1;box-shadow:0 -2px 12px rgba(0,0,0,.35)}
        #cf-toolbar .cf-bar{display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;padding:4px 10px;cursor:pointer;user-select:none}
        #cf-toolbar .cf-chip b{color:#a5b4fc;font-weight:600}
        #cf-toolbar .cf-toggle{margin-left:auto;background:none;border:1px solid #475569;color:#cbd5e1;border-radius:4px;padding:1px 8px;font:inherit;cursor:pointer}
        #cf-toolbar .cf-body{display:none;max-height:45vh;overflow:auto;border-top:1px solid #334155;background:#111827}
        #cf-toolbar.cf-open .cf-body{display:block}
        #cf-toolbar .cf-tabs{display:flex;gap:2px;padding:6px 10px 0;position:sticky;top:0;background:#111827}
        #cf-toolbar .cf-tab{background:#1f2937;border:1px solid #374151;border-bottom:none;color:#cbd5e1;padding:3px 10px;font:inherit;cursor:pointer;border-radius:4px 4px 0 0}
        #cf-toolbar .cf-tab.cf-active{background:#312e81;color:#fff}
        #cf-toolbar .cf-panel{display:none;padding:8px 10px 12px}
        #cf-toolbar .cf-panel.cf-active{display:block}
        #cf-toolbar table{border-collapse:collapse;width:100%}
        #cf-toolbar th,#cf-toolbar td{text-align:left;vertical-align:top;padding:3px 8px;border-bottom:1px solid #1f2937}
        #cf-toolbar th{color:#a5b4fc;font-weight:600;white-space:nowrap}
        #cf-toolbar code{color:#f1f5f9;white-space:pre-wrap;word-break:break-word;font:inherit}
        #cf-toolbar tr.cf-slow td{background:#451a03;color:#fde68a}
        #cf-toolbar .cf-muted{color:#94a3b8;margin:4px 0}
        </style>
        <div id="cf-toolbar" role="complementary" aria-label="Debug toolbar">
            <div class="cf-bar">$bar<button type="button" class="cf-toggle" aria-expanded="false">details</button></div>
            <div class="cf-body">
                <div class="cf-tabs">$tabButtons</div>
                $tabPanels
            </div>
        </div>
        <script nonce="$nonce">
        (function () {
            var root = document.getElementById('cf-toolbar');
            var toggle = root.querySelector('.cf-toggle');
            var key = 'cf-toolbar-open';
            function setOpen(open) {
                root.classList.toggle('cf-open', open);
                toggle.textContent = open ? 'hide' : 'details';
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                try { localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
            }
            root.querySelector('.cf-bar').addEventListener('click', function () {
                setOpen(!root.classList.contains('cf-open'));
            });
            root.querySelectorAll('.cf-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    root.querySelectorAll('.cf-tab, .cf-panel').forEach(function (el) { el.classList.remove('cf-active'); });
                    tab.classList.add('cf-active');
                    root.querySelector('[data-cf-panel="' + tab.getAttribute('data-cf-tab') + '"]').classList.add('cf-active');
                });
            });
            try { if (localStorage.getItem(key) === '1') setOpen(true); } catch (e) {}
        })();
        </script>

        HTML;
    }
}
