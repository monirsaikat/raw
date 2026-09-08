<?php

// Rate limiting backed by the file cache. The 'throttle:max,minutes'
// middleware keys on client IP + method + path, so it protects a single
// endpoint (login, register, contact) without touching the rest of the site.

// Counts one attempt for $key. The counter file is read, incremented and
// written under a single exclusive lock, so concurrent requests never lose
// increments and the whole operation costs one file open.
function rate_limit_attempt(string $key, int $max, int $decaySeconds): array
{
    $directory = cache_path();

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Cache directory [$directory] is not writable.");
    }

    $handle = @fopen(cache_file('rate-limit:' . $key), 'c+b');

    if ($handle === false) {
        throw new RuntimeException('Unable to open the rate-limit counter file.');
    }

    flock($handle, LOCK_EX);

    $now = time();
    $entry = @unserialize((string) stream_get_contents($handle));

    if (!is_array($entry) || ($entry['expires'] ?? 0) <= $now) {
        $entry = ['count' => 0, 'expires' => $now + $decaySeconds];
    }

    $entry['count']++;

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, serialize($entry));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

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
