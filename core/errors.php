<?php

// Error and exception handling. Every PHP warning/notice becomes an
// ErrorException so bugs surface instead of being printed into the page;
// deprecations are logged. Uncaught exceptions are logged (5xx only) and
// rendered as a debug page, a JSON payload, or the views/errors template.

function http_status_text(int $status): string
{
    static $texts = [
        200 => 'OK', 201 => 'Created', 204 => 'No Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other', 304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 408 => 'Request Timeout',
        409 => 'Conflict', 410 => 'Gone', 413 => 'Payload Too Large', 415 => 'Unsupported Media Type',
        419 => 'Page Expired', 422 => 'Unprocessable Content', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
        503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    return $texts[$status] ?? 'Error';
}

function abort(int $status, string $message = '', array $headers = []): never
{
    throw new HttpException($status, $message, $headers);
}

function abort_if($condition, int $status, string $message = '', array $headers = []): void
{
    if ($condition) {
        abort($status, $message, $headers);
    }
}

function abort_unless($condition, int $status, string $message = '', array $headers = []): void
{
    if (!$condition) {
        abort($status, $message, $headers);
    }
}

function register_error_handlers(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');

    set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
        // Respect the @ operator and error_reporting() adjustments.
        if (!(error_reporting() & $errno)) {
            return false;
        }

        if ($errno & (E_DEPRECATED | E_USER_DEPRECATED)) {
            log_message('notice', 'Deprecated: {message} in {file}:{line}', [
                'message' => $errstr, 'file' => $errfile, 'line' => $errline,
            ]);

            return true;
        }

        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    set_exception_handler('handle_exception');

    // Fatal errors bypass set_error_handler; catch them at shutdown so the
    // user still sees a proper error page instead of a blank screen.
    register_shutdown_function(function (): void {
        $error = error_get_last();

        if ($error !== null && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR))) {
            handle_exception(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        }
    });
}

function handle_exception(Throwable $e): void
{
    $status = $e instanceof HttpException ? $e->status : 500;

    if ($status >= 500) {
        log_exception($e);
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, sprintf(
            "\n%s: %s\n  in %s:%d\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            APP_DEBUG ? $e->getTraceAsString() . "\n" : ''
        ));

        exit(1);
    }

    // Discard any partial output so the error page renders cleanly.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    try {
        $response = render_exception($e);
    } catch (Throwable $inner) {
        log_exception($inner);

        $response = new Response(
            $status . ' ' . http_status_text($status),
            $status,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    $response->send();
}

function render_exception(Throwable $e): Response
{
    if ($e instanceof ValidationException) {
        if (wants_json()) {
            return json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        flash('errors', $e->errors);
        flash('old', $e->input);

        return back();
    }

    $status = $e instanceof HttpException ? $e->status : 500;
    $headers = $e instanceof HttpException ? $e->headers : [];

    // Never leak internal exception messages on a 5xx in production; an
    // explicit abort(503, '...') message is intentional and passes through.
    $message = $e instanceof HttpException && $e->getMessage() !== ''
        ? $e->getMessage()
        : http_status_text($status);

    if (wants_json()) {
        $payload = ['message' => $message];

        if (APP_DEBUG && $status >= 500) {
            $payload['exception'] = get_class($e);
            $payload['message'] = $e->getMessage();
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
            $payload['trace'] = array_map('debug_frame_label', $e->getTrace());
        }

        return json($payload, $status, $headers);
    }

    if (APP_DEBUG && $status >= 500) {
        return new Response(debug_error_page($e), $status, $headers);
    }

    return error_page($status, $message, $headers);
}

// Renders views/errors/{status}.tpl, falling back to views/errors/error.tpl,
// then to plain text if the view layer itself is unavailable.
function error_page(int $status, string $message = '', array $headers = []): Response
{
    $data = [
        'status' => $status,
        'title' => http_status_text($status),
        'message' => $message !== '' ? $message : http_status_text($status),
    ];

    foreach (['errors/' . $status, 'errors/error'] as $template) {
        if (view_exists($template)) {
            return new Response(view($template, $data), $status, $headers);
        }
    }

    return new Response(
        $status . ' ' . http_status_text($status),
        $status,
        $headers + ['Content-Type' => 'text/plain; charset=utf-8']
    );
}

function debug_frame_label(array $frame): string
{
    $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
    $location = isset($frame['file']) ? $frame['file'] . ':' . ($frame['line'] ?? 0) : '[internal]';

    return $location . ' ' . $call . '()';
}

function debug_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function debug_code_excerpt(string $file, int $line, int $context = 7): string
{
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $start = max(1, $line - $context);
    $end = min(count($lines), $line + $context);
    $html = '';

    for ($i = $start; $i <= $end; $i++) {
        $class = $i === $line ? ' class="hl"' : '';
        $html .= '<tr' . $class . '><td class="ln">' . $i . '</td><td><pre>' . debug_escape($lines[$i - 1]) . '</pre></td></tr>';
    }

    return '<table class="code">' . $html . '</table>';
}

// Debug-only page: exception chain, code excerpt, trace (without arguments,
// so credentials passed to constructors never reach the browser), request
// summary and the SQL log. Everything is escaped.
function debug_error_page(Throwable $e): string
{
    $chain = [];

    for ($current = $e; $current !== null; $current = $current->getPrevious()) {
        $chain[] = $current;
    }

    $sections = '';

    foreach ($chain as $index => $exception) {
        $trace = '';

        foreach ($exception->getTrace() as $i => $frame) {
            $trace .= '<li><span class="idx">#' . $i . '</span> ' . debug_escape(debug_frame_label($frame)) . '</li>';
        }

        $sections .= '<section>'
            . '<h2>' . ($index > 0 ? 'Caused by: ' : '') . debug_escape(get_class($exception)) . '</h2>'
            . '<p class="msg">' . debug_escape($exception->getMessage()) . '</p>'
            . '<p class="loc">' . debug_escape($exception->getFile()) . ':' . $exception->getLine() . '</p>'
            . debug_code_excerpt($exception->getFile(), $exception->getLine())
            . '<h3>Stack trace</h3><ol class="trace">' . $trace . '</ol>'
            . '</section>';
    }

    $route = function_exists('current_route') ? current_route() : null;
    $action = $route['action'] ?? null;

    $request = [
        'Method' => function_exists('request_method') ? request_method() : ($_SERVER['REQUEST_METHOD'] ?? ''),
        'Path' => function_exists('request_path') ? request_path() : ($_SERVER['REQUEST_URI'] ?? ''),
        'Route' => $route ? (($route['name'] ?? '-') . ' → ' . (is_string($action) ? $action : 'Closure')) : '-',
        'IP' => $_SERVER['REMOTE_ADDR'] ?? '',
        'Time' => round((microtime(true) - APP_START) * 1000) . ' ms',
        'Memory' => round(memory_get_peak_usage(true) / 1048576, 1) . ' MB',
        'PHP' => PHP_VERSION,
    ];

    $requestRows = '';

    foreach ($request as $label => $value) {
        $requestRows .= '<tr><th>' . debug_escape($label) . '</th><td>' . debug_escape($value) . '</td></tr>';
    }

    $queries = '';

    if (class_exists('Database', false)) {
        foreach (Database::queryLog() as $query) {
            $queries .= '<li><code>' . debug_escape($query['sql']) . '</code> <span class="meta">'
                . debug_escape(json_encode($query['bindings'])) . ' · ' . $query['time'] . ' ms</span></li>';
        }
    }

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . debug_escape(get_class($e)) . '</title>'
        . '<style>'
        . 'body{margin:0;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1f2328}'
        . 'header{background:#b42318;color:#fff;padding:1.5rem 2rem}header h1{margin:0;font-size:1.1rem;font-weight:600;opacity:.85}'
        . 'header p{margin:.35rem 0 0;font-size:1.35rem;font-weight:700;word-break:break-word}'
        . 'main{padding:1.5rem 2rem;max-width:1100px}section{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:1.25rem 1.5rem;margin-bottom:1.25rem}'
        . 'h2{margin:0;font-size:1rem}h3{font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:1.25rem 0 .5rem}'
        . '.msg{font-size:1.05rem;font-weight:600;margin:.4rem 0}.loc{color:#6b7280;margin:0 0 1rem;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.85rem}'
        . 'table.code{border-collapse:collapse;width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem;background:#0f172a;color:#e2e8f0;border-radius:8px;overflow:hidden}'
        . 'table.code td{padding:0 .75rem;white-space:pre}table.code td.ln{color:#64748b;text-align:right;user-select:none;width:1%}table.code tr.hl{background:#7f1d1d}'
        . 'table.code pre{margin:0;font:inherit}'
        . 'ol.trace{margin:0;padding-left:1.25rem;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem}ol.trace li{padding:.15rem 0;word-break:break-all}.idx{color:#6b7280}'
        . 'table.req{border-collapse:collapse}table.req th{text-align:left;padding:.25rem 1rem .25rem 0;color:#6b7280;font-weight:500}table.req td{padding:.25rem 0}'
        . 'ul.sql{padding-left:1.25rem;font-size:.85rem}ul.sql code{font-family:ui-monospace,Menlo,Consolas,monospace}.meta{color:#6b7280}'
        . '</style></head><body>'
        . '<header><h1>' . debug_escape(get_class($e)) . '</h1><p>' . debug_escape($e->getMessage()) . '</p></header>'
        . '<main>' . $sections
        . '<section><h2>Request</h2><table class="req">' . $requestRows . '</table></section>'
        . ($queries !== '' ? '<section><h2>Queries</h2><ul class="sql">' . $queries . '</ul></section>' : '')
        . '</main></body></html>';
}
