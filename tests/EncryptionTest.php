<?php

// Encrypter, encrypt()/decrypt() helpers and encrypted cookies.

before_each(function () {
    config_set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    unset($GLOBALS['__queued_cookies']);
});

test('encrypt and decrypt round-trip strings, arrays and scalars', function () {
    assert_same('hello', decrypt(encrypt('hello')));
    assert_same(['a' => 1, 'b' => [true, null, 'é']], decrypt(encrypt(['a' => 1, 'b' => [true, null, 'é']])));
    assert_same(42, decrypt(encrypt(42)));
    assert_same('raw', decrypt_string(encrypt_string('raw')));
    assert_same('', decrypt_string(encrypt_string('')));
});

test('every encryption uses a fresh IV and the payload is opaque base64 JSON', function () {
    $a = encrypt_string('same');
    $b = encrypt_string('same');

    assert_true($a !== $b, 'random IV');

    $json = json_decode(base64_decode($a, true), true);
    assert_same(['iv', 'value', 'tag'], array_keys($json));
    assert_not_contains('same', base64_decode($json['value']));
});

test('tampered, truncated and foreign-key payloads throw DecryptException', function () {
    $payload = encrypt_string('secret');

    $json = json_decode(base64_decode($payload), true);
    $cipher = base64_decode($json['value']);
    $cipher[0] = chr(ord($cipher[0]) ^ 1);
    $json['value'] = base64_encode($cipher);

    assert_throws(fn () => decrypt_string(base64_encode(json_encode($json))), DecryptException::class);
    assert_throws(fn () => decrypt_string('not-a-payload'), DecryptException::class);
    assert_throws(fn () => decrypt_string(base64_encode('{"iv":"x"}')), DecryptException::class);
    assert_throws(fn () => decrypt_string(substr($payload, 0, 20)), DecryptException::class);

    config_set('app.key', 'base64:' . base64_encode(str_repeat('z', 32)));
    assert_throws(fn () => decrypt_string($payload), DecryptException::class);
});

test('keys accept the base64 prefix, raw 32-byte strings and hashed short strings', function () {
    $raw = str_repeat('k', 32);
    $fromBase64 = new Encrypter('base64:' . base64_encode($raw));
    $fromRaw = new Encrypter($raw);
    $fromShort = new Encrypter('short passphrase');

    assert_same('x', $fromRaw->decrypt_string($fromBase64->encrypt_string('x')), 'same key either way');
    assert_same('y', $fromShort->decrypt_string($fromShort->encrypt_string('y')));
    assert_throws(fn () => $fromShort->decrypt_string($fromRaw->encrypt_string('x')), DecryptException::class);

    assert_throws(fn () => new Encrypter(''), RuntimeException::class, 'APP_KEY');
    assert_throws(fn () => new Encrypter('base64:***'), RuntimeException::class);
});

test('cookie() encrypts and cookie_get() decrypts, tamper yields null', function () {
    cookie('theme', 'dark', 60);

    assert_true($_COOKIE['theme'] !== 'dark', 'stored encrypted');
    assert_same('dark', cookie_get('theme'));
    assert_same('dark', cookie('theme'), 'cookie() without a value reads');
    assert_true(cookie_has('theme'));
    assert_same(['theme' => ['value' => 'dark', 'minutes' => 60]], cookies_queued());

    $_COOKIE['theme'] = strrev($_COOKIE['theme']);
    assert_null(cookie_get('theme'));
    assert_same('light', cookie_get('theme', 'light'));

    $_COOKIE['theme'] = 'plain text';
    assert_null(cookie_get('theme'));

    cookie_forget('theme');
    assert_null(cookie_get('theme'));
    assert_false(cookie_has('theme'));
    assert_same([], cookies_queued());
    assert_null(cookie_get('missing'));
});

test('cookie options inherit the session settings', function () {
    config_set('session.path', '/app');
    config_set('session.domain', 'example.com');

    $options = cookie_options(['samesite' => 'Strict']);

    assert_same('/app', $options['path']);
    assert_same('example.com', $options['domain']);
    assert_true($options['httponly']);
    assert_same('Strict', $options['samesite']);
    assert_false($options['secure']);

    $_SERVER['HTTPS'] = 'on';
    assert_true(cookie_options()['secure']);
});

test('the encrypter instance follows the configured key', function () {
    $first = encrypter();
    assert_same($first, encrypter(), 'cached');

    config_set('app.key', 'other');
    assert_true($first !== encrypter());
});
