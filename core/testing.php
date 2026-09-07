<?php

// Minimal test runner — no PHPUnit required. Files in tests/*.php register
// cases with test('name', fn () => ...) and use the assert_* helpers. Run
// with `php console.php test` (optionally followed by a filename filter).

class TestFailure extends Exception
{
}

class TestSkipped extends Exception
{
}

$__tests = [];

// Marks the current test as skipped (e.g. no database available).
function skip(string $reason): never
{
    throw new TestSkipped($reason);
}

function test(string $name, callable $callback): void
{
    global $__tests;

    $__tests[] = ['name' => $name, 'callback' => $callback];
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

// Clean slate before every test: no input, no session, no routes, no user.
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

    foreach (['HTTP_ACCEPT', 'CONTENT_TYPE', 'HTTP_CONTENT_TYPE', 'HTTP_REFERER', 'HTTP_X_CSRF_TOKEN', 'HTTP_X_REQUESTED_WITH', 'HTTPS'] as $key) {
        unset($_SERVER[$key]);
    }

    $GLOBALS['__session_cli_started'] = false;

    Config::reset();
    routes_reset();
    auth_reset();
    global_middleware(['csrf']);
}

// Simulates the boundary between two requests: session data persists,
// flash data ages, per-request caches are dropped.
function test_next_request(): void
{
    $GLOBALS['__session_cli_started'] = false;
    auth_reset();
}

function run_tests(array $files): int
{
    global $__tests;

    $passed = 0;
    $skipped = 0;
    $failures = [];
    $start = microtime(true);

    foreach ($files as $file) {
        $__tests = [];

        require $file;

        echo "\n" . basename($file, '.php') . "\n";

        foreach ($__tests as $case) {
            test_reset_state();

            try {
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
            }
        }
    }

    $elapsed = round((microtime(true) - $start) * 1000);
    $failed = count($failures);

    echo "\n" . ($failed === 0 ? 'OK' : 'FAILED') . " — $passed passed, $failed failed"
        . ($skipped > 0 ? ", $skipped skipped" : '') . " ({$elapsed} ms)\n";

    return $failed === 0 ? 0 : 1;
}
