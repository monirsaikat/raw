<?php

// HTTP caching, opt-in per route.
//
//   'cache.headers:public,max_age=3600,etag'   Cache-Control + ETag, answers
//                                              304 to If-None-Match /
//                                              If-Modified-Since
//   'cache.response:600'                       stores the whole GET response
//                                              in the file cache for 600 s
//
// Both wrap whatever the route returns (string, array, Response) as a
// Response and only act on successful GET/HEAD responses.

// Directive tokens use '_' because ',' separates middleware parameters:
// max_age=60 → "max-age=60", s_maxage=600 → "s-maxage=600".
function http_cache_control(array $directives): string
{
    $parts = [];

    foreach ($directives as $directive) {
        $directive = trim((string) $directive);

        if ($directive === '' || $directive === 'etag' || str_starts_with($directive, 'last_modified=')) {
            continue;
        }

        $parts[] = str_replace('_', '-', $directive);
    }

    return implode(', ', $parts);
}

function http_etag(string $body): string
{
    return '"' . sha1($body) . '"';
}

// If-None-Match may list several tags, weak ones (W/"..."), or "*".
function http_etag_matches(string $header, string $etag): bool
{
    if (trim($header) === '*') {
        return true;
    }

    foreach (explode(',', $header) as $candidate) {
        $candidate = trim($candidate);

        if (str_starts_with($candidate, 'W/')) {
            $candidate = substr($candidate, 2);
        }

        if ($candidate === $etag) {
            return true;
        }
    }

    return false;
}

// True when the client's cached copy is still fresh: the ETag matches, or
// (without an ETag comparison) the resource has not changed since the date
// the client sent.
function http_not_modified(Response $response): bool
{
    $etag = $response->headers['Etag'] ?? null;
    $ifNoneMatch = request_header('If-None-Match');

    if (is_string($ifNoneMatch) && $etag !== null) {
        return http_etag_matches($ifNoneMatch, $etag);
    }

    $lastModified = $response->headers['Last-Modified'] ?? null;
    $ifModifiedSince = request_header('If-Modified-Since');

    if (is_string($ifModifiedSince) && $lastModified !== null) {
        $since = strtotime($ifModifiedSince);
        $modified = strtotime($lastModified);

        return $since !== false && $modified !== false && $modified <= $since;
    }

    return false;
}

// A 304 carries the validators and caching headers but no body or entity
// headers.
function http_not_modified_response(Response $response): Response
{
    $keep = ['Etag', 'Last-Modified', 'Cache-Control', 'Expires', 'Vary'];
    $headers = array_intersect_key($response->headers, array_flip($keep));

    return new Response('', 304, $headers);
}

function http_cacheable_request(): bool
{
    return in_array(request_real_method(), ['GET', 'HEAD'], true);
}

middleware('cache.headers', function (callable $next, ...$directives) {
    $response = response($next());

    if (!http_cacheable_request() || $response->status !== 200) {
        return $response;
    }

    $cacheControl = http_cache_control($directives);

    if ($cacheControl !== '') {
        $response->header('Cache-Control', $cacheControl);
    }

    foreach ($directives as $directive) {
        if (str_starts_with($directive, 'last_modified=')) {
            $time = (int) substr($directive, 14);
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $time) . ' GMT');
        }
    }

    if (in_array('etag', $directives, true) && !isset($response->headers['Etag'])) {
        $response->header('ETag', http_etag($response->body));
    }

    return http_not_modified($response) ? http_not_modified_response($response) : $response;
});

// ------------------------------------------------------- response caching --

function response_cache_key(?string $url = null): string
{
    return 'response:' . ($url ?? request_url());
}

function response_cache_forget(string $url): void
{
    cache_forget(response_cache_key($url));
}

// Personalised responses must never be shared: skip when the visitor is
// logged in or has flash messages waiting. Both reads keep the cookie-less
// fast path (no session is started for a client without a cookie).
function response_cache_skip(): bool
{
    if (!http_cacheable_request()) {
        return true;
    }

    if (!session_readable()) {
        return false;
    }

    return session_get(auth_session_key()) !== null || flash_all() !== [];
}

middleware('cache.response', function (callable $next, $ttl = 60) {
    if (response_cache_skip()) {
        return $next();
    }

    $key = response_cache_key();
    $cached = cache_get($key);

    if (is_array($cached) && isset($cached['body'], $cached['status'], $cached['headers'])) {
        return (new Response($cached['body'], $cached['status'], $cached['headers']))
            ->header('X-Cache', 'HIT')
            ->header('Age', (string) max(0, time() - (int) ($cached['time'] ?? time())));
    }

    $response = response($next());

    // The action may have logged someone in or flashed a message; a response
    // that says so is private too.
    $cacheControl = strtolower($response->headers['Cache-Control'] ?? '');
    $private = str_contains($cacheControl, 'no-store') || str_contains($cacheControl, 'private');

    if ($response->status === 200 && !$private && !response_cache_skip()) {
        cache_set($key, [
            'body' => $response->body,
            'status' => $response->status,
            'headers' => $response->headers,
            'time' => time(),
        ], max(1, (int) $ttl));
    }

    return $response->header('X-Cache', 'MISS');
});
