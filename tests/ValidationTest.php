<?php

test('required rejects empty values and produces a readable message', function () {
    $errors = validate(['first_name' => ''], ['first_name' => 'required']);

    assert_same('The first name field is required.', $errors['first_name'][0]);
    assert_same([], validate(['first_name' => 'Ann'], ['first_name' => 'required']));
    assert_same([], validate(['n' => '0'], ['n' => 'required']), '"0" counts as present');
    assert_count(1, validate([], ['missing' => 'required']));
});

test('non-implicit rules skip empty values', function () {
    assert_same([], validate(['email' => ''], ['email' => 'email|max:5']));
    assert_same([], validate([], ['email' => 'email']));
    assert_same([], validate(['email' => null], ['email' => 'nullable|email']));
});

test('format rules: email, url, ip, json, alpha variants, digits', function () {
    assert_count(1, validate(['e' => 'nope'], ['e' => 'email']));
    assert_same([], validate(['e' => 'a@b.co'], ['e' => 'email']));
    assert_count(1, validate(['u' => 'not a url'], ['u' => 'url']));
    assert_same([], validate(['u' => 'https://example.com/x'], ['u' => 'url']));
    assert_same([], validate(['i' => '10.0.0.1'], ['i' => 'ip']));
    assert_count(1, validate(['i' => '999.1.1.1'], ['i' => 'ip']));
    assert_same([], validate(['j' => '{"a":1}'], ['j' => 'json']));
    assert_count(1, validate(['j' => '{a:1}'], ['j' => 'json']));
    assert_same([], validate(['a' => 'José'], ['a' => 'alpha']));
    assert_count(1, validate(['a' => 'a b'], ['a' => 'alpha']));
    assert_same([], validate(['a' => 'ab12'], ['a' => 'alpha_num']));
    assert_same([], validate(['a' => 'ab-1_2'], ['a' => 'alpha_dash']));
    assert_count(1, validate(['a' => 'ab 1'], ['a' => 'alpha_dash']));
    assert_same([], validate(['d' => '1234'], ['d' => 'digits:4']));
    assert_count(1, validate(['d' => '123'], ['d' => 'digits:4']));
});

test('type rules: numeric, integer, boolean, string, array', function () {
    assert_same([], validate(['n' => '1.5'], ['n' => 'numeric']));
    assert_count(1, validate(['n' => '1.5'], ['n' => 'integer']));
    assert_same([], validate(['n' => '42'], ['n' => 'integer']));
    assert_same([], validate(['b' => '0'], ['b' => 'boolean']));
    assert_count(1, validate(['b' => 'maybe'], ['b' => 'boolean']));
    assert_count(1, validate(['s' => ['x']], ['s' => 'string']));
    assert_same([], validate(['a' => ['x']], ['a' => 'array']));
    assert_count(1, validate(['a' => 'x'], ['a' => 'array']));
});

test('size rules measure strings, numbers and arrays appropriately', function () {
    assert_count(1, validate(['s' => 'abc'], ['s' => 'min:5']));
    assert_same([], validate(['s' => 'abcdef'], ['s' => 'min:5|max:10']));
    assert_count(1, validate(['s' => 'abcdefghijk'], ['s' => 'max:10']));

    assert_count(1, validate(['n' => '5'], ['n' => 'numeric|min:10']));
    assert_same([], validate(['n' => '15'], ['n' => 'numeric|min:10|max:20']));
    assert_same([], validate(['n' => '15'], ['n' => 'integer|between:10,20']));
    assert_count(1, validate(['n' => '25'], ['n' => 'integer|between:10,20']));

    assert_count(1, validate(['a' => [1, 2, 3]], ['a' => 'array|max:2']));
    assert_same([], validate(['a' => [1, 2]], ['a' => 'array|size:2']));

    $errors = validate(['bio' => str_repeat('x', 11)], ['bio' => 'max:10']);
    assert_same('The bio may not be greater than 10.', $errors['bio'][0]);
});

test('membership and comparison rules', function () {
    assert_same([], validate(['r' => 'admin'], ['r' => 'in:admin,editor']));
    assert_count(1, validate(['r' => 'guest'], ['r' => 'in:admin,editor']));
    assert_same([], validate(['r' => 'guest'], ['r' => 'not_in:admin,editor']));

    assert_same([], validate(['p' => 'secret', 'p_confirmation' => 'secret'], ['p' => 'confirmed']));
    assert_count(1, validate(['p' => 'secret', 'p_confirmation' => 'other'], ['p' => 'confirmed']));
    assert_same([], validate(['a' => 'x', 'b' => 'x'], ['a' => 'same:b']));
    assert_count(1, validate(['a' => 'x', 'b' => 'x'], ['a' => 'different:b']));
});

test('regex rules keep pipes, colons and commas intact', function () {
    $rules = ['t' => ['required', 'regex:/^\d{2}:\d{2}$/']];

    assert_same([], validate(['t' => '10:30'], $rules));
    assert_count(1, validate(['t' => '1030'], $rules));
    assert_same([], validate(['c' => 'a,b'], ['c' => ['regex:/^(a|b),(a|b)$/']]));
    assert_count(1, validate(['c' => 'a,b'], ['c' => ['not_regex:/^(a|b),(a|b)$/']]));
    assert_same([], validate(['s' => 'https://x'], ['s' => 'starts_with:http://,https://']));
    assert_count(1, validate(['s' => 'ftp://x'], ['s' => 'starts_with:http://,https://']));
});

test('date rules', function () {
    assert_same([], validate(['d' => '2026-09-07'], ['d' => 'date']));
    assert_count(1, validate(['d' => 'not a date'], ['d' => 'date']));
    assert_same([], validate(['d' => '2026-09-07'], ['d' => 'date_format:Y-m-d']));
    assert_count(1, validate(['d' => '07/09/2026'], ['d' => 'date_format:Y-m-d']));
    assert_same([], validate(['d' => '2020-01-01'], ['d' => 'before:2021-01-01']));
    assert_count(1, validate(['d' => '2022-01-01'], ['d' => 'before:2021-01-01']));
    assert_same([], validate(['d' => '2022-01-01'], ['d' => 'after:2021-01-01']));
});

test('conditional requirement rules', function () {
    assert_count(1, validate(['type' => 'company', 'vat' => ''], ['vat' => 'required_if:type,company']));
    assert_same([], validate(['type' => 'person', 'vat' => ''], ['vat' => 'required_if:type,company']));
    assert_count(1, validate(['a' => 'x', 'b' => ''], ['b' => 'required_with:a']));
    assert_same([], validate(['a' => '', 'b' => ''], ['b' => 'required_with:a']));
    assert_count(1, validate(['a' => '', 'b' => ''], ['b' => 'required_without:a']));
    assert_count(1, validate(['terms' => 'no'], ['terms' => 'accepted']));
    assert_same([], validate(['terms' => 'on'], ['terms' => 'accepted']));
});

test('sometimes skips absent fields; present requires the key', function () {
    assert_same([], validate([], ['nick' => 'sometimes|required|min:3']));
    assert_count(1, validate(['nick' => 'ab'], ['nick' => 'sometimes|required|min:3']));
    assert_count(1, validate([], ['nick' => 'present']));
    assert_same([], validate(['nick' => ''], ['nick' => 'present']));
});

test('only the first failing rule per field is reported', function () {
    $errors = validate(['n' => ''], ['n' => 'required|min:3|email']);

    assert_count(1, $errors['n']);
    assert_same('The n field is required.', $errors['n'][0]);
});

test('custom messages override per field.rule, then per rule', function () {
    $errors = validate(['name' => '', 'bio' => str_repeat('x', 20)], [
        'name' => 'required',
        'bio' => 'max:10',
    ], [
        'name.required' => 'Name please.',
        'max' => ':field is limited to :max characters.',
    ]);

    assert_same('Name please.', $errors['name'][0]);
    assert_same('bio is limited to 10 characters.', $errors['bio'][0]);
});

test('validator_extend registers custom rules with messages', function () {
    validator_extend('even', fn ($value) => (int) $value % 2 === 0, 'The :field must be even.');

    assert_same([], validate(['n' => '4'], ['n' => 'even']));
    assert_same('The n must be even.', validate(['n' => '3'], ['n' => 'even'])['n'][0]);
});

test('unknown rules throw instead of silently passing', function () {
    assert_throws(fn () => validate(['x' => 'y'], ['x' => 'no_such_rule']), InvalidArgumentException::class, 'Unknown validation rule');
});

test('validated returns only the requested keys', function () {
    $data = validated([
        'name' => 'Ann',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'extra' => 'ignored',
        '_token' => 'abc',
    ], [
        'name' => 'required',
        'password' => 'required|min:8|confirmed',
    ]);

    assert_same(['name' => 'Ann', 'password' => 'secret123'], $data);
});

test('validated throws a ValidationException carrying errors and filtered old input', function () {
    $e = assert_throws(
        fn () => validated(['name' => '', '_token' => 't', 'password' => 'x', 'email' => 'a@b.c'], ['name' => 'required']),
        ValidationException::class
    );

    assert_same(422, $e->status);
    assert_key_exists('name', $e->errors);
    assert_same(['name' => '', 'email' => 'a@b.c'], $e->input);
});
