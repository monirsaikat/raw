<?php

test('session helpers set, get, check, pull and forget values', function () {
    session_set('k', 'v');

    assert_true(session_has('k'));
    assert_same('v', session_get('k'));
    assert_same('d', session_get('missing', 'd'));
    assert_same('v', session_pull('k'));
    assert_false(session_has('k'));

    session_set('a', 1);
    session_flush();
    assert_same([], session_all());
});

test('flash data is visible for exactly one following request', function () {
    flash('success', 'Saved');

    assert_null(flash('success'), 'not visible in the request that set it');
    assert_false(flash_has('success'));

    test_next_request();

    assert_same('Saved', flash('success'));
    assert_same('Saved', flash('success'), 'reads are repeatable');
    assert_true(flash_has('success'));

    test_next_request();

    assert_null(flash('success'));
});

test('flash_now is visible immediately and gone next request', function () {
    flash_now('info', 'Now');

    assert_same('Now', flash('info'));
    assert_same(['info' => 'Now'], flash_all());

    test_next_request();

    assert_null(flash('info'));
});

test('flash_keep carries the current flash bag one request further', function () {
    flash('warning', 'W');
    test_next_request();

    assert_same('W', flash('warning'));
    flash_keep();
    test_next_request();

    assert_same('W', flash('warning'));
    test_next_request();

    assert_null(flash('warning'));
});

test('old() reads flashed input with a default', function () {
    flash('old', ['email' => 'a@b.c']);
    test_next_request();

    assert_same('a@b.c', old('email'));
    assert_same('', old('name'));
    assert_same('x', old('name', 'x'));
});

test('back_with_errors flashes errors and filtered input', function () {
    $_POST = ['name' => 'Ann', 'password' => 'secret', '_token' => 't'];

    $response = back_with_errors(['name' => ['Bad']]);

    assert_same(302, $response->status);
    test_next_request();

    assert_same(['name' => ['Bad']], flash('errors'));
    assert_same(['name' => 'Ann'], flash('old'));
});
