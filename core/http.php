<?php

// Request helpers (read the current HTTP request) and response helpers
// (build Response objects). Input never includes cookies — unlike $_REQUEST —
// and JSON bodies are merged in transparently for API clients.

// ---------------------------------------------------------------- request --

function request_real_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

// HTML forms can only send GET/POST, so PUT/PATCH/DELETE routes are reached
// through a `_method` field on a POST (see {method_field}).
function request_method(): string
{
    $method = request_real_method();

    if ($method === 'POST') {
        $spoof = strtoupper((string) ($_POST['_method'] ?? ''));

        if (in_array($spoof, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $spoof;
        }
    }

    return $method;
}

function request_uri(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    return is_string($path) && $path !== '' ? $path : '/';
}

// Path relative to the app root, normalised: "/saikat/test1/about/" → "/about".
function request_path(): string
{
    $uri = request_uri();
    $base = base_path();

    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }

    return '/' . trim($uri, '/');
}

function request_host(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

    // Reject header-injected junk; hosts are letters, digits, dots, dashes, port.
    return preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) ? $host : 'localhost';
}

function request_is_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function request_url(): string
{
    return (request_is_secure() ? 'https' : 'http') . '://' . request_host() . ($_SERVER['REQUEST_URI'] ?? '/');
}

function request_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function request_header(string $name, $default = null)
{
    $key = strtoupper(str_replace('-', '_', $name));

    return $_SERVER['HTTP_' . $key] ?? $_SERVER[$key] ?? $default;
}

function request_is_json(): bool
{
    return str_contains(strtolower((string) request_header('Content-Type', '')), 'json');
}

function request_is_ajax(): bool
{
    return strtolower((string) request_header('X-Requested-With', '')) === 'xmlhttprequest';
}

// True when the client asked for JSON (Accept header) or sent JSON without
// accepting HTML — used to choose between HTML and JSON error responses.
function wants_json(): bool
{
    $accept = strtolower((string) request_header('Accept', ''));

    if (str_contains($accept, 'application/json') || str_contains($accept, '+json')) {
        return true;
    }

    return request_is_json() && !str_contains($accept, 'text/html');
}

function request_body(): string
{
    // Cached per request; the test client pre-fills it for fake requests.
    if (!array_key_exists('__request_body', $GLOBALS)) {
        $GLOBALS['__request_body'] = (string) file_get_contents('php://input');
    }

    return (string) $GLOBALS['__request_body'];
}

function request_json(): array
{
    if (!request_is_json()) {
        return [];
    }

    $decoded = json_decode(request_body(), true);

    return is_array($decoded) ? $decoded : [];
}

// All input: query string, then form body or JSON body (body wins). No cookies.
function request(?string $key = null, $default = null)
{
    $input = array_merge($_GET, request_is_json() ? request_json() : $_POST);

    if ($key === null) {
        return $input;
    }

    return $input[$key] ?? $default;
}

function query(?string $key = null, $default = null)
{
    if ($key === null) {
        return $_GET;
    }

    return $_GET[$key] ?? $default;
}

function input_trim($value)
{
    if (is_string($value)) {
        return trim($value);
    }

    if (is_array($value)) {
        return array_map('input_trim', $value);
    }

    return $value;
}

// Same as request() with whitespace trimmed — what forms should read.
function input(?string $key = null, $default = null)
{
    if ($key === null) {
        return input_trim(request());
    }

    $value = request($key);

    return $value === null ? $default : input_trim($value);
}

function input_only(array $keys): array
{
    return array_intersect_key(input(), array_flip($keys));
}

function input_except(array $keys): array
{
    return array_diff_key(input(), array_flip($keys));
}

function has_input(string $key): bool
{
    $value = input($key);

    return $value !== null && $value !== '' && $value !== [];
}

// Uploaded file as a normalised array, or null when absent / not uploaded.
function request_file(string $key): ?array
{
    $file = $_FILES[$key] ?? null;

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return $file;
}

// Previous request's input, flashed by validated()/back_with_errors().
function old(string $key, $default = '')
{
    return flash('old')[$key] ?? $default;
}

// --------------------------------------------------------------- response --

function response($body = '', int $status = 200, array $headers = []): Response
{
    if ($body instanceof Response) {
        return $body;
    }

    if (is_array($body) || $body instanceof JsonSerializable) {
        return json($body, $status, $headers);
    }

    return new Response((string) $body, $status, $headers);
}

function json($data, int $status = 200, array $headers = []): Response
{
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return new Response($body, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
}

function redirect(string $url, int $status = 302): Response
{
    return new Response('', $status, ['Location' => $url]);
}

function redirect_route(string $name, array $params = [], int $status = 302): Response
{
    return redirect(route_url($name, $params), $status);
}

// Redirects to the previous page: the Referer when it is ours, else the last
// GET URL recorded in the session, else $fallback (or the home page).
function back(?string $fallback = null, int $status = 302): Response
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

    if ($referer !== '' && url_is_local($referer)) {
        return redirect($referer, $status);
    }

    $previous = session_get('_previous_url');

    if (is_string($previous) && url_is_local($previous)) {
        return redirect($previous, $status);
    }

    return redirect($fallback ?? url('/'), $status);
}

// A URL is "local" when it is relative or points at this host — safe to
// redirect to without opening an open-redirect hole.
function url_is_local(string $url): bool
{
    if (str_starts_with($url, '//') || str_contains($url, "\n") || str_contains($url, "\r")) {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);

    if ($host === null || $host === false) {
        return str_starts_with($url, '/');
    }

    return strcasecmp((string) $host, preg_replace('/:\d+$/', '', request_host())) === 0;
}

// Sends whatever an action returned: Response, array/JsonSerializable (as
// JSON), string (as-is) or null (nothing).
function send_response($result): void
{
    if ($result === null) {
        return;
    }

    if (!$result instanceof Response) {
        $result = is_array($result) || $result instanceof JsonSerializable
            ? json($result)
            : new Response((string) $result);
    }

    // Debug toolbar (core/modules/90-toolbar.php): appends its panel to HTML
    // pages when APP_DEBUG is on; a no-op otherwise.
    if (APP_DEBUG && function_exists('toolbar_inject')) {
        toolbar_inject($result);
    }

    $result->send();
}
