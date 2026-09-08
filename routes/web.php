<?php

// Route definitions: get/post/put/patch/delete/any(path, action, name, middleware).
// Actions are 'Controller@method' strings so `php console.php route:cache`
// can serialise them; closures work too but cannot be cached.
// Placeholders: {id}, {id:\d+} (regex constraint), {slug?} (optional).

get('/', 'HomeController@index', 'home');
get('/about', 'HomeController@about', 'about');
get('/user/{id:\d+}', 'UserController@show', 'user');

get('/contact', 'ContactController@index', 'contact');
post('/contact', 'ContactController@store', 'contact.store', ['throttle:10,1']);

group(['middleware' => ['guest']], function () {
    get('/register', 'AuthController@showRegister', 'register');
    post('/register', 'AuthController@register', 'register.store', ['throttle:5,1']);
    get('/login', 'AuthController@showLogin', 'login');
    post('/login', 'AuthController@login', 'login.store', ['throttle:5,1']);
});

group(['middleware' => ['auth']], function () {
    get('/account', 'AuthController@account', 'account');
    post('/logout', 'AuthController@logout', 'logout');
});

group(['prefix' => '/api', 'name' => 'api.'], function () {
    get('/ping', 'ApiController@ping', 'ping');
});

// TEMP-BENCH-INFO
get('/bench/info', fn () => json(['framework' => 'ComfreePHP', 'php' => PHP_VERSION, 'included_files' => count(get_included_files()), 'peak_memory_mb' => round(memory_get_peak_usage() / 1048576, 1)]));
