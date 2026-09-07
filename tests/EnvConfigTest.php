<?php

test('load_env parses quotes, comments, export and inline comments', function () {
    $file = sys_get_temp_dir() . '/env-test-' . bin2hex(random_bytes(4));
    $p = 'T' . strtoupper(bin2hex(random_bytes(3))) . '_';

    file_put_contents($file, implode("\n", [
        '# a comment',
        "{$p}A=plain",
        "{$p}B=\"quoted value\"",
        "{$p}C='single # not a comment'",
        "export {$p}D=exported",
        "{$p}E=true",
        "{$p}F=false",
        "{$p}G=null",
        "{$p}H=with # inline comment",
        "{$p}I=",
        "{$p}J=a=b=c",
        'not a pair',
        "bad key!={$p}",
    ]));

    load_env($file);
    unlink($file);

    assert_same('plain', env($p . 'A'));
    assert_same('quoted value', env($p . 'B'));
    assert_same('single # not a comment', env($p . 'C'));
    assert_same('exported', env($p . 'D'));
    assert_true(env($p . 'E'));
    assert_false(env($p . 'F'));
    assert_null(env($p . 'G', 'default'));
    assert_same('with', env($p . 'H'));
    assert_same('', env($p . 'I'));
    assert_same('a=b=c', env($p . 'J'));
    assert_same('dflt', env($p . 'MISSING', 'dflt'));
});

test('real environment variables win over the file unless overwriting', function () {
    $file = sys_get_temp_dir() . '/env-test-' . bin2hex(random_bytes(4));
    $key = 'T' . strtoupper(bin2hex(random_bytes(3)));

    putenv("$key=real");
    file_put_contents($file, "$key=file\n");

    load_env($file);
    assert_same('real', env($key));

    load_env($file, true);
    assert_same('file', env($key));

    unlink($file);
});

test('config reads dot paths with defaults and supports runtime overrides', function () {
    assert_same('mysql', config('database.connections.mysql.driver'));
    assert_same('x', config('database.nope', 'x'));
    assert_same('x', config('database.connections.mysql.driver.deeper', 'x'), 'scalar cannot be descended');
    assert_same([], config('no_such_file'));

    config_set('app.custom.deep', 1);
    assert_same(1, config('app.custom.deep'));
    assert_true(Config::has('app.name'));
    assert_false(Config::has('app.zzz'));

    config_set('app.name', 'Renamed');
    assert_same('Renamed', config('app.name'));
    assert_same('Renamed', app_name());
});

test('string helpers', function () {
    assert_same('user_profile', str_snake('UserProfile'));
    assert_same('html_parser', str_snake('HTMLParser'));
    assert_same('user-profile', str_snake('UserProfile', '-'));
    assert_same('UserProfile', str_studly('user_profile'));
    assert_same('userProfile', str_camel('user-profile'));
    assert_same('users', str_plural('user'));
    assert_same('categories', str_plural('category'));
    assert_same('boxes', str_plural('box'));
    assert_same('statuses', str_plural('status'));
    assert_same('days', str_plural('day'));
    assert_same('hello-world', str_slug('Héllo Wörld!'));
    assert_same('hello_world', str_slug('Hello World', '_'));
    assert_same('Hello...', str_limit('Hello there', 5));
    assert_same('Hi', str_limit('Hi', 5));
    assert_same(8, strlen(str_random(8)));
    assert_same('Model', class_basename('App\\Core\\Model'));
});
