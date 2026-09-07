<?php

// Rate limiting backed by the file cache. The 'throttle:max,minutes'
// middleware keys on client IP + method + path, so it protects a single
// endpoint (login, register, contact) without touching the rest of the site.

function rate_limit_attempt(string $key, int $max, int $decaySeconds): array
{
    $cacheKey = 'rate-limit:' . $key;
    $now = time();
    $entry = cache_get($cacheKey);

    if (!is_array($entry) || ($entry['expires'] ?? 0) <= $now) {
        $entry = ['count' => 0, 'expires' => $now + $decaySeconds];
    }

    $entry['count']++;

    cache_set($cacheKey, $entry, max(1, $entry['expires'] - $now));

    return [
        'allowed' => $entry['count'] <= $max,
        'remaining' => max(0, $max - $entry['count']),
        'retry_after' => max(1, $entry['expires'] - $now),
    ];
}

function rate_limit_clear(string $key): void
{
    cache_forget('rate-limit:' . $key);
}

middleware('throttle', function (callable $next, $max = 60, $minutes = 1) {
    $key = request_ip() . '|' . request_method() . '|' . request_path();
    $limit = rate_limit_attempt($key, (int) $max, (int) $minutes * 60);

    if (!$limit['allowed']) {
        abort(
            429,
            'Too many requests. Please try again in ' . $limit['retry_after'] . ' seconds.',
            ['Retry-After' => (string) $limit['retry_after']]
        );
    }

    return $next();
});
