<?php

return [
    // Guard used when none is named: auth_user(), ['auth'], guard().
    'default' => env('AUTH_GUARD', 'web'),

    // One entry per kind of login. A guard may set model, username,
    // session_key, login_route, login_path, home_route and remember; missing
    // keys fall back to the top-level values below. Non-default guards get
    // their own session key ('auth_id_admin') and cookie ('remember_me_admin')
    // automatically, so a customer and a staff member can be logged in at
    // the same time. Use them with auth_user('admin'), ['auth:admin'],
    // ['guest:admin'], ['guard:admin'] and guard('admin')->attempt(...).
    'guards' => [
        'web' => [
            'model' => 'User',
        ],
        // 'admin' => [
        //     'model' => 'Admin',
        //     'login_route' => 'admin.login',
        //     'login_path' => '/admin/login',
        //     'home_route' => 'admin.dashboard',
        // ],
    ],

    // Model class that represents an authenticated user (default for guards).
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
