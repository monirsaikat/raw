<?php

// Minimal test runner — no PHPUnit required. Files in tests/*.php register
// cases with test('name', fn () => ...) and use the assert_* helpers. Run
// with `php console.php test` (optionally followed by a filename filter).
//
// Also here: before_each()/after_each() hooks, an in-process HTTP client
// (http_get(), http_post(), ...) returning TestResponse, an in-memory
// SQLite database for the app (use_test_database()) and database assertions.

class TestFailure extends Exception
{
}

class TestSkipped extends Exception
{
}

$__tests = [];
$__hooks = ['before' => [], 'after' => []];

function test(string $name, callable $callback): void
{
    global $__tests;

    $__tests[] = ['name' => $name, 'callback' => $callback];
}

// Runs before every test in the current file (after the state reset).
function before_each(callable $callback): void
{
    global $__hooks;

    $__hooks['before'][] = $callback;
}

// Runs after every test in the current file, even when it failed.
function after_each(callable $callback): void
{
    global $__hooks;

    $__hooks['after'][] = $callback;
}

// Marks the current test as skipped (e.g. no database available).
function skip(string $reason): never
{
    throw new TestSkipped($reason);
}

function fail(string $message): never
{
    throw new TestFailure($message);
}

function test_export($value): string
{
    if (is_string($value) && strlen($value) > 120) {
        $value = substr($value, 0, 117) . '...';
    }

    return str_replace("\n", ' ', var_export($value, true));
}

// ---------------------------------------------------------------- asserts --

function assert_true($condition, string $message = ''): void
{
    if ($condition !== true) {
        fail($message !== '' ? $message : 'Expected true, got ' . test_export($condition));
    }
}

function assert_false($condition, string $message = ''): void
{
    if ($condition !== false) {
        fail($message !== '' ? $message : 'Expected false, got ' . test_export($condition));
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected ' . test_export($expected) . ', got ' . test_export($actual));
    }
}

function assert_equals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected ' . test_export($expected) . ', got ' . test_export($actual));
    }
}

function assert_null($actual, string $message = ''): void
{
    assert_same(null, $actual, $message);
}

function assert_not_null($actual, string $message = ''): void
{
    if ($actual === null) {
        fail($message !== '' ? $message : 'Expected a non-null value');
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . 'expected to find ' . test_export($needle) . ' in ' . test_export($haystack));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . 'did not expect to find ' . test_export($needle) . ' in ' . test_export($haystack));
    }
}

function assert_matches(string $pattern, string $subject, string $message = ''): void
{
    if (!preg_match($pattern, $subject)) {
        fail(($message !== '' ? $message . ': ' : '') . test_export($subject) . ' does not match ' . $pattern);
    }
}

function assert_count(int $expected, $actual, string $message = ''): void
{
    $count = is_countable($actual) ? count($actual) : -1;

    if ($count !== $expected) {
        fail(($message !== '' ? $message . ': ' : '') . "expected $expected items, got $count");
    }
}

function assert_key_exists($key, array $array, string $message = ''): void
{
    if (!array_key_exists($key, $array)) {
        fail(($message !== '' ? $message . ': ' : '') . 'missing key ' . test_export($key));
    }
}

function assert_instance_of(string $class, $actual, string $message = ''): void
{
    if (!$actual instanceof $class) {
        fail(($message !== '' ? $message . ': ' : '') . "expected an instance of $class, got " . (is_object($actual) ? get_class($actual) : gettype($actual)));
    }
}

function assert_throws(callable $callback, string $class = Throwable::class, ?string $messageContains = null): Throwable
{
    try {
        $callback();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            fail('Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
        }

        if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
            fail('Exception message ' . test_export($e->getMessage()) . ' does not contain ' . test_export($messageContains));
        }

        return $e;
    }

    fail('Expected ' . $class . ' to be thrown');
}

// --------------------------------------------------------------- database --

// Points the default connection at an in-memory SQLite database with the
// app's migrations applied. Call it in before_each() (with a transaction
// that after_each() rolls back) for tests that touch the app's tables.
function use_test_database(string $name = 'app_testing'): string
{
    static $migrated = [];

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        skip('pdo_sqlite is not loaded');
    }

    if (!isset($migrated[$name])) {
        Database::addConnection($name, ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    config_set('database.default', $name);

    if (!isset($migrated[$name])) {
        (new Migrator(BASE_PATH . '/database/migrations', $name))->run();
        $migrated[$name] = true;
    }

    return $name;
}

function assert_database_has(string $table, array $where, string $message = ''): void
{
    if (!Database::table($table)->where($where)->exists()) {
        fail(($message !== '' ? $message . ': ' : '') . "no row in [$table] matching " . test_export($where));
    }
}

function assert_database_missing(string $table, array $where, string $message = ''): void
{
    if (Database::table($table)->where($where)->exists()) {
        fail(($message !== '' ? $message . ': ' : '') . "unexpected row in [$table] matching " . test_export($where));
    }
}

function assert_database_count(string $table, int $expected, string $message = ''): void
{
    $count = Database::table($table)->count();

    if ($count !== $expected) {
        fail(($message !== '' ? $message . ': ' : '') . "expected $expected rows in [$table], got $count");
    }
}

// ------------------------------------------------------------ http client --

// Loads routes/web.php so requests hit the real application.
function http_use_app_routes(): void
{
    routes_reset();

    require BASE_PATH . '/routes/web.php';
}

// Subsequent requests run as this user (null → guest again).
function acting_as(?Model $user): void
{
    $GLOBALS['__test_acting_as'] = $user;

    if ($user === null) {
        session_forget(auth_session_key());
        auth_set_user(null);

        return;
    }

    session_set(auth_session_key(), $user->getKey());
    auth_set_user($user);
}

// Performs an in-process request against the registered routes: globals are
// set up like a real request, middleware and the action run, and the result
// is captured. The session ($_SESSION) persists between requests like a
// browser cookie would; flash data ages exactly as in production.
function http_request(string $method, string $uri, array $data = [], array $headers = [], array $server = []): TestResponse
{
    $method = strtoupper($method);
    $parts = parse_url($uri) ?: [];
    $path = $parts['path'] ?? '/';
    $query = [];

    parse_str($parts['query'] ?? '', $query);

    foreach (array_keys($_SERVER) as $key) {
        if (str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            unset($_SERVER[$key]);
        }
    }

    $isJson = isset($headers['Content-Type']) && str_contains(strtolower($headers['Content-Type']), 'json');

    $_GET = $query;
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_URI'] = $path . ($query !== [] ? '?' . http_build_query($query) : '');
    $_SERVER['HTTP_HOST'] = $server['HTTP_HOST'] ?? 'localhost';
    $_SERVER['REMOTE_ADDR'] = $server['REMOTE_ADDR'] ?? '203.0.113.1';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

    if (isset($GLOBALS['__test_last_url']) && !isset($headers['Referer'])) {
        $headers['Referer'] = $GLOBALS['__test_last_url'];
    }

    foreach ($headers as $name => $value) {
        $key = strtoupper(str_replace('-', '_', $name));
        $_SERVER['HTTP_' . $key] = $value;

        if ($key === 'CONTENT_TYPE') {
            $_SERVER['CONTENT_TYPE'] = $value;
        }
    }

    foreach ($server as $key => $value) {
        $_SERVER[$key] = $value;
    }

    // Age flash data and drop per-request caches, but keep the session.
    test_next_request();

    if (array_key_exists('__test_acting_as', $GLOBALS) && $GLOBALS['__test_acting_as'] instanceof Model) {
        session_set(auth_session_key(), $GLOBALS['__test_acting_as']->getKey());
        auth_set_user($GLOBALS['__test_acting_as']);
    }

    $unsafe = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);

    if ($isJson) {
        $_SERVER['REQUEST_METHOD'] = $method;
        $GLOBALS['__request_body'] = $data === [] ? '' : json_encode($data, JSON_THROW_ON_ERROR);

        if ($unsafe && !isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        }
    } else {
        // Browsers can only send GET/POST forms; other verbs travel as _method.
        $_SERVER['REQUEST_METHOD'] = in_array($method, ['PUT', 'PATCH', 'DELETE'], true) ? 'POST' : $method;
        $GLOBALS['__request_body'] = '';

        if ($unsafe) {
            $_POST = $data;

            if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
                $_POST['_method'] = $method;
            }

            if (!array_key_exists('_token', $_POST)) {
                $_POST['_token'] = csrf_token();
            }
        } elseif ($data !== []) {
            $_GET = array_merge($_GET, $data);
            $_SERVER['REQUEST_URI'] = $path . '?' . http_build_query($_GET);
        }
    }

    $exception = null;

    try {
        $result = dispatch(request_method(), request_path());
    } catch (Throwable $e) {
        $exception = $e;
        $result = render_exception($e);
    }

    if ($result instanceof Response) {
        $response = $result;
    } elseif (is_array($result) || $result instanceof JsonSerializable) {
        $response = json($result);
    } else {
        $response = new Response((string) $result);
    }

    if ($method === 'GET' && !wants_json() && !request_is_ajax()) {
        session_set('_previous_url', request_url());
    }

    $GLOBALS['__test_last_url'] = request_url();

    return new TestResponse($response, $exception);
}

function http_get(string $uri, array $headers = []): TestResponse
{
    return http_request('GET', $uri, [], $headers);
}

function http_post(string $uri, array $data = [], array $headers = []): TestResponse
{
    return http_request('POST', $uri, $data, $headers);
}

function http_put(string $uri, array $data = [], array $headers = []): TestResponse
{
    return http_request('PUT', $uri, $data, $headers);
}

function http_patch(string $uri, array $data = [], array $headers = []): TestResponse
{
    return http_request('PATCH', $uri, $data, $headers);
}

function http_delete(string $uri, array $data = [], array $headers = []): TestResponse
{
    return http_request('DELETE', $uri, $data, $headers);
}

// Sends a JSON body and asks for JSON back.
function http_json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    return http_request($method, $uri, $data, $headers + [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);
}

// Follows local redirects with GET requests until a non-redirect arrives.
function http_follow(TestResponse $response, int $maxRedirects = 5): TestResponse
{
    while ($maxRedirects-- > 0 && $response->status() >= 300 && $response->status() < 400) {
        $location = (string) $response->header('Location');
        $path = (string) (parse_url($location, PHP_URL_PATH) ?? '/');
        $base = base_path();

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $query = parse_url($location, PHP_URL_QUERY);
        $response = http_get(($path === '' ? '/' : $path) . ($query ? '?' . $query : ''));
    }

    return $response;
}

// ------------------------------------------------------------------ state --

// Clean slate before every test: no input, no session, no routes, no user,
// fresh container and gate.
function test_reset_state(): void
{
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_COOKIE = [];
    $_SESSION = [];

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    foreach (array_keys($_SERVER) as $key) {
        if (str_starts_with($key, 'HTTP_') && $key !== 'HTTP_HOST') {
            unset($_SERVER[$key]);
        }
    }

    unset($_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTPS']);
    unset($GLOBALS['__request_body'], $GLOBALS['__test_acting_as'], $GLOBALS['__test_last_url']);

    $GLOBALS['__session_cli_started'] = false;

    Config::reset();
    routes_reset();
    auth_reset();
    global_middleware(['csrf']);

    app()->flush();
    container_boot();
    gate_boot();
}

// Simulates the boundary between two requests: session data persists,
// flash data ages, per-request caches are dropped.
function test_next_request(): void
{
    $GLOBALS['__session_cli_started'] = false;

    unset($GLOBALS['__request_body']);
    auth_reset();
}

// ----------------------------------------------------------------- runner --

function run_tests(array $files): int
{
    global $__tests, $__hooks;

    $passed = 0;
    $skipped = 0;
    $failures = [];
    $start = microtime(true);

    foreach ($files as $file) {
        $__tests = [];
        $__hooks = ['before' => [], 'after' => []];

        require $file;

        echo "\n" . basename($file, '.php') . "\n";

        foreach ($__tests as $case) {
            test_reset_state();

            try {
                foreach ($__hooks['before'] as $hook) {
                    $hook();
                }

                ($case['callback'])();

                $passed++;
                echo '  ok   ' . $case['name'] . "\n";
            } catch (TestSkipped $e) {
                $skipped++;
                echo '  skip ' . $case['name'] . ' (' . $e->getMessage() . ")\n";
            } catch (TestFailure $e) {
                $failures[] = $case['name'];
                echo '  FAIL ' . $case['name'] . "\n       " . $e->getMessage() . "\n";
            } catch (Throwable $e) {
                $failures[] = $case['name'];
                echo '  FAIL ' . $case['name'] . "\n       " . get_class($e) . ': ' . $e->getMessage()
                    . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n";
            } finally {
                foreach ($__hooks['after'] as $hook) {
                    try {
                        $hook();
                    } catch (Throwable $e) {
                        echo '       after_each failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
                    }
                }
            }
        }
    }

    $elapsed = round((microtime(true) - $start) * 1000);
    $failed = count($failures);

    echo "\n" . ($failed === 0 ? 'OK' : 'FAILED') . " — $passed passed, $failed failed"
        . ($skipped > 0 ? ", $skipped skipped" : '') . " ({$elapsed} ms)\n";

    return $failed === 0 ? 0 : 1;
}
