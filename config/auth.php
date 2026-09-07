<?php

return [
    // Model class that represents an authenticated user.
    'model' => 'User',

    // Column matched against the first argument of auth_attempt().
    'username' => 'email',

    // Session key that holds the logged-in user's id.
    'session_key' => 'auth_id',

    // Named routes used by the 'auth' and 'guest' middleware.
    'login_route' => 'login',
    'home_route' => 'home',

    // "Remember me" cookie. Requires a nullable `remember_token` column on
    // the users table (see the 2026_09_07 migration).
    'remember' => [
        'cookie' => env('REMEMBER_COOKIE', 'remember_me'),
        'days' => 30,
    ],
];
