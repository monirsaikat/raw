<?php

// ComfreePHP comparison benchmark: routes registered without the web
// middleware group (no session, no CSRF), like an API endpoint.

use Illuminate\Support\Facades\Route;

Route::get('/bench/json', fn () => response()->json(['pong' => true, 'time' => now()->format('Y-m-d H:i:s')]));

Route::get('/bench/info', fn () => response()->json([
    'framework' => 'Laravel ' . app()->version(),
    'php' => PHP_VERSION,
    'included_files' => count(get_included_files()),
    'peak_memory_mb' => round(memory_get_peak_usage() / 1048576, 1),
]));
