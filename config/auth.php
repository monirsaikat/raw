<?php

return [
    // Model class that represents an authenticated user.
    'model' => 'User',

    // Column matched against the first argument of auth_attempt().
    'username' => 'email',

    // Session key that holds the logged-in user's id.
    'session_key' => 'auth_id',

    // Named routes used by the 'auth' and 'guest' middleware. When no route
    // called login_route exists, guests are sent to login_path instead.
    'login_route' => 'login',
    'login_path' => '/login',
    'home_route' => 'home',

    // Model class => policy class. A "<Model>Policy" class in policies/ is
    // picked up automatically; list only the exceptions here.
    'policies' => [
        // 'Post' => 'PostPolicy',
    ],

    // "Remember me" cookie. Requires a nullable `remember_token` column on
    // the users table (see the 2026_09_07 migration).
    'remember' => [
        'cookie' => env('REMEMBER_COOKIE', 'remember_me'),
        'days' => 30,
    ],
];
