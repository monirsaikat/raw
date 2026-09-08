<?php

// Plain PHP baseline: how much a request loads and uses with no framework.

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'framework' => 'Raw PHP',
    'php' => PHP_VERSION,
    'opcache' => function_exists('opcache_get_status') && (bool) (opcache_get_status(false)['opcache_enabled'] ?? false),
    'included_files' => count(get_included_files()),
    'peak_memory_mb' => round(memory_get_peak_usage() / 1048576, 1),
]);
