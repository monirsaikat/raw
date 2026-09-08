<?php

// Session drivers: driver resolution, the array/cookie/database handlers
// and the sessions table migration. Handlers are exercised directly; PHP
// itself only calls them under a web SAPI.

before_each(function () {
    config_set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
    config_set('session.name', 'test_session');
    ArraySessionHandler::flush();
});

test('the driver comes from config and file stays the native handler', function () {
    assert_same('file', session_driver());
    assert_null(session_driver_handler());
    assert_null(session_driver_handler('file'));

    config_set('session.driver', 'array');
    assert_same('array', session_driver());
    assert_instance_of(ArraySessionHandler::class, session_driver_handler());
    assert_instance_of(CookieSessionHandler::class, session_driver_handler('cookie'));
    assert_instance_of(DatabaseSessionHandler::class, session_driver_handler('database'));

    assert_throws(fn () => session_driver_handler('redis'), RuntimeException::class, 'Unknown session driver');
});

test('session_driver_install is a no-op for the file driver under the CLI', function () {
    assert_null(session_driver_install());

    config_set('session.driver', 'array');
    assert_instance_of(ArraySessionHandler::class, session_driver_install());
});

test('array handler stores, validates, destroys and collects sessions', function () {
    $handler = new ArraySessionHandler();

    assert_true($handler->open('', 'x'));
    assert_same('', $handler->read('abc'));
    assert_false($handler->validateId('abc'));

    assert_true($handler->write('abc', 'a|s:1:"x";'));
    assert_same('a|s:1:"x";', $handler->read('abc'));
    assert_true($handler->validateId('abc'));
    assert_true($handler->updateTimestamp('abc', 'a|s:1:"x";'));

    assert_same(0, $handler->gc(60), 'fresh sessions survive');
    assert_same(1, $handler->gc(-1), 'expired ones go');
    assert_same('', $handler->read('abc'));

    $handler->write('abc', 'v');
    assert_true($handler->destroy('abc'));
    assert_same([], ArraySessionHandler::all());
    assert_true($handler->close());
});

test('cookie handler round-trips an encrypted payload bound to the session id', function () {
    $handler = new CookieSessionHandler('test_session_payload', 3600);

    assert_same('test_session_payload', $handler->cookie_name());
    assert_same('', $handler->read('id1'));

    assert_true($handler->write('id1', 'auth_id|i:5;'));

    $raw = $_COOKIE['test_session_payload'];
    assert_not_contains('auth_id', $raw, 'encrypted');
    assert_same(['id' => 'id1', 'data' => 'auth_id|i:5;'], array_intersect_key(decrypt($raw), ['id' => 1, 'data' => 1]));

    assert_same('auth_id|i:5;', $handler->read('id1'));
    assert_true($handler->validateId('id1'));
    assert_same('', $handler->read('id2'), 'payload is bound to its id');
    assert_false($handler->validateId('id2'));

    $_COOKIE['test_session_payload'] = 'tampered';
    assert_same('', $handler->read('id1'));

    $handler->write('id1', 'x');
    assert_true($handler->destroy('id1'));
    assert_false(isset($_COOKIE['test_session_payload']));
    assert_same(0, $handler->gc(10));
});

test('cookie handler expires old payloads and refuses oversized ones', function () {
    $handler = new CookieSessionHandler('test_session_payload', 60);

    $_COOKIE['test_session_payload'] = encrypt(['id' => 'id1', 'data' => 'old', 'time' => time() - 120]);
    assert_same('', $handler->read('id1'));

    $handler->write('id1', 'small');
    $before = $_COOKIE['test_session_payload'];

    $handler->write('id1', str_repeat('x', CookieSessionHandler::MAX_SIZE));
    assert_same($before, $_COOKIE['test_session_payload'], 'oversized write is dropped, previous cookie kept');
    assert_same('small', $handler->read('id1'));
});

test('database handler persists sessions with user, ip and agent columns', function () {
    use_test_database();

    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
    $_SESSION['auth_id'] = 42;

    $handler = new DatabaseSessionHandler('sessions', null, 3600);

    assert_same('', $handler->read('sess1'));
    assert_false($handler->validateId('sess1'));

    assert_true($handler->write('sess1', 'auth_id|i:42;'));
    assert_same('auth_id|i:42;', $handler->read('sess1'));
    assert_true($handler->validateId('sess1'));

    $row = Database::table('sessions')->where('id', 'sess1')->first();
    assert_same(42, (int) $row['user_id']);
    assert_same('TestBrowser/1.0', $row['user_agent']);
    assert_null($row['ip_address'], 'no client IP under the CLI');
    assert_true(base64_decode($row['payload'], true) === 'auth_id|i:42;', 'payload is base64');

    // A second write updates in place.
    unset($_SESSION['auth_id']);
    $handler->write('sess1', 'k|s:1:"v";');
    assert_database_count('sessions', 1);
    assert_same('k|s:1:"v";', $handler->read('sess1'));
    assert_null(Database::table('sessions')->where('id', 'sess1')->first()['user_id']);

    assert_true($handler->destroy('sess1'));
    assert_database_missing('sessions', ['id' => 'sess1']);
});

test('database handler garbage-collects and ignores expired rows', function () {
    use_test_database();

    $handler = new DatabaseSessionHandler('sessions', null, 60);
    $handler->write('fresh', 'a');
    $handler->write('stale', 'b');
    Database::table('sessions')->where('id', 'stale')->update(['last_activity' => time() - 3600]);

    assert_same('', $handler->read('stale'), 'expired before GC ran');
    assert_true($handler->updateTimestamp('fresh', 'a'));

    assert_same(1, $handler->gc(60));
    assert_database_has('sessions', ['id' => 'fresh']);
    assert_database_missing('sessions', ['id' => 'stale']);

    Database::table('sessions')->delete();
});

test('the sessions migration ships and session:table detects it', function () {
    $files = glob(BASE_PATH . '/database/migrations/*_create_sessions_table.php');

    assert_count(1, $files);
    assert_instance_of(Migration::class, require $files[0]);
});

test('session config exposes driver, table and lazy_write defaults', function () {
    assert_same('file', config('session.driver'));
    assert_same('sessions', config('session.table'));
    assert_true(config('session.lazy_write'));
});
