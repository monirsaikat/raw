<?php

// File-based cache in storage/cache. Values are serialised, so anything
// serialisable (arrays, models) works. TTL is in seconds; 0 means forever.
// Writers take an exclusive lock and readers a shared one, so a reader
// never sees a half-written entry (and no temp-file rename is needed,
// which is expensive on Windows).

function cache_path(): string
{
    return BASE_PATH . '/storage/cache';
}

function cache_file(string $key): string
{
    return cache_path() . '/' . sha1($key) . '.cache';
}

function cache_get(string $key, $default = null)
{
    $file = cache_file($key);

    if (!is_file($file)) {
        return $default;
    }

    $handle = @fopen($file, 'rb');

    if ($handle === false) {
        return $default;
    }

    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($raw === false || $raw === '') {
        return $default;
    }

    $entry = @unserialize($raw);

    if (!is_array($entry) || !array_key_exists('value', $entry)) {
        return $default;
    }

    if ($entry['expires'] !== 0 && $entry['expires'] <= time()) {
        @unlink($file);

        return $default;
    }

    return $entry['value'];
}

function cache_set(string $key, $value, int $ttl = 3600): void
{
    $directory = cache_path();

    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Cache directory [$directory] is not writable.");
    }

    $file = cache_file($key);

    $payload = serialize([
        'expires' => $ttl > 0 ? time() + $ttl : 0,
        'value' => $value,
    ]);

    $handle = @fopen($file, 'cb');

    if ($handle === false) {
        throw new RuntimeException("Unable to write cache file [$file].");
    }

    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    fwrite($handle, $payload);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function cache_has(string $key): bool
{
    $missing = new stdClass();

    return cache_get($key, $missing) !== $missing;
}

function cache_forget(string $key): void
{
    $file = cache_file($key);

    if (is_file($file)) {
        @unlink($file);
    }
}

// Returns the cached value, computing and storing it with $callback on a miss.
function cache_remember(string $key, int $ttl, callable $callback)
{
    $missing = new stdClass();
    $value = cache_get($key, $missing);

    if ($value !== $missing) {
        return $value;
    }

    $value = $callback();
    cache_set($key, $value, $ttl);

    return $value;
}

function cache_flush(): int
{
    $count = 0;

    foreach (glob(cache_path() . '/*.cache') ?: [] as $file) {
        if (@unlink($file)) {
            $count++;
        }
    }

    return $count;
}
