<?php

return [
    // view('auth/login') → views/auth/login.tpl
    'paths' => BASE_PATH . '/views',

    // Compiled templates (safe to delete; `php console.php view:clear`).
    'compiled' => BASE_PATH . '/storage/views',
    'cache' => BASE_PATH . '/storage/cache/smarty',

    // Auto-escape {$var} output. Use {$var|raw} for trusted HTML.
    'escape_html' => true,

    // Re-compile templates when the .tpl file changes. Off in production
    // saves a filemtime() per template per request.
    'compile_check' => APP_DEBUG,

    // PHP functions exposed as Smarty tags: {navigate name='home'}, {asset path='...'}
    'functions' => [
        'navigate' => 'navigate',
        'url' => 'url',
        'asset' => 'asset',
        'csrf_field' => 'csrf_field',
        'csrf_meta' => 'csrf_meta',
        'method_field' => 'method_field',
        'current_year' => 'current_year',
        'perf_stats' => 'perf_stats',
        'csp_nonce' => 'csp_nonce',
    ],

    // PHP functions exposed as Smarty modifiers: {if 'home'|route_is},
    // {if 'update'|can:$post}
    'modifiers' => [
        'route_is' => 'route_is',
        'can' => 'can',
        'cannot' => 'cannot',
    ],
];
