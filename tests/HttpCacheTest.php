<?php

// cache.headers (Cache-Control, ETag, 304) and cache.response middleware.

$httpCacheHits = 0;

before_each(function () {
    global $httpCacheHits;

    $httpCacheHits = 0;
    cache_flush();

    get('/page', function () use (&$httpCacheHits) {
        $httpCacheHits++;

        return 'page body';
    }, 'page', ['cache.headers:public,max_age=3600,etag']);

    get('/stamped', fn () => 'stamped', 'stamped', ['cache.headers:private,must_revalidate,last_modified=1700000000']);

    get('/json', fn () => ['n' => 1], 'json', ['cache.headers:no_store']);

    get('/cached', function () use (&$httpCacheHits) {
        $httpCacheHits++;

        return response('expensive ' . $httpCacheHits, 200, ['X-Custom' => 'yes']);
    }, 'cached', ['cache.response:60']);
});

after_each(fn () => cache_flush());

test('cache.headers sets Cache-Control and a strong ETag on 200 GET responses', function () {
    $response = http_get('/page');

    $response->assertOk()
        ->assertHeader('Cache-Control', 'public, max-age=3600')
        ->assertHeader('ETag', '"' . sha1('page body') . '"');

    assert_same('page body', $response->body());
});

test('directives translate underscores and wrap array responses', function () {
    http_get('/json')->assertOk()->assertHeader('Cache-Control', 'no-store')->assertHeaderMissing('ETag');

    http_get('/stamped')
        ->assertHeader('Cache-Control', 'private, must-revalidate')
        ->assertHeader('Last-Modified', 'Tue, 14 Nov 2023 22:13:20 GMT');
});

test('If-None-Match with the current ETag answers 304 without a body', function () {
    $etag = http_get('/page')->header('ETag');

    $response = http_get('/page', ['If-None-Match' => $etag]);

    $response->assertStatus(304)->assertHeader('ETag', $etag)->assertHeader('Cache-Control', 'public, max-age=3600');
    assert_same('', $response->body());

    http_get('/page', ['If-None-Match' => 'W/' . $etag])->assertStatus(304);
    http_get('/page', ['If-None-Match' => '"other", ' . $etag])->assertStatus(304);
    http_get('/page', ['If-None-Match' => '*'])->assertStatus(304);
    http_get('/page', ['If-None-Match' => '"stale"'])->assertOk();
});

test('If-Modified-Since answers 304 when the resource is not newer', function () {
    http_get('/stamped', ['If-Modified-Since' => 'Tue, 14 Nov 2023 22:13:20 GMT'])->assertStatus(304);
    http_get('/stamped', ['If-Modified-Since' => 'Wed, 15 Nov 2023 00:00:00 GMT'])->assertStatus(304);
    http_get('/stamped', ['If-Modified-Since' => 'Mon, 13 Nov 2023 00:00:00 GMT'])->assertOk();
    http_get('/stamped', ['If-Modified-Since' => 'garbage'])->assertOk();
});

test('cache.headers leaves non-GET and non-200 responses alone', function () {
    post('/page', fn () => 'posted', null, ['cache.headers:public,etag']);
    get('/missing', fn () => response('nope', 404), null, ['cache.headers:public,etag']);

    http_post('/page')->assertOk()->assertHeaderMissing('Cache-Control')->assertHeaderMissing('ETag');
    http_get('/missing')->assertNotFound()->assertHeaderMissing('ETag');
});

test('cache.response serves the stored response for later requests', function () {
    global $httpCacheHits;

    $first = http_get('/cached');
    $first->assertOk()->assertHeader('X-Cache', 'MISS')->assertHeader('X-Custom', 'yes');
    assert_same('expensive 1', $first->body());

    $second = http_get('/cached');
    $second->assertOk()->assertHeader('X-Cache', 'HIT')->assertHeader('X-Custom', 'yes')->assertHeader('Age');
    assert_same('expensive 1', $second->body());
    assert_same(1, $httpCacheHits, 'action ran once');

    response_cache_forget('http://localhost/cached');
    assert_same('expensive 2', http_get('/cached')->body());
});

test('cache.response is keyed by the full URL', function () {
    http_get('/cached?a=1');
    http_get('/cached?a=2');

    assert_same('expensive 1', http_get('/cached?a=1')->body());
    assert_same('expensive 2', http_get('/cached?a=2')->body());
});

test('cache.response bypasses logged-in visitors, flash messages and unsafe methods', function () {
    global $httpCacheHits;

    post('/cached', fn () => 'posted', null, ['cache.response:60']);

    session_set(auth_session_key(), 1);
    http_get('/cached')->assertHeaderMissing('X-Cache');
    http_get('/cached')->assertHeaderMissing('X-Cache');
    assert_same(2, $httpCacheHits);

    session_forget(auth_session_key());
    flash('success', 'Saved');
    http_get('/cached')->assertHeaderMissing('X-Cache');
    assert_same(3, $httpCacheHits);

    http_get('/cached')->assertHeader('X-Cache', 'MISS');
    http_get('/cached')->assertHeader('X-Cache', 'HIT');

    http_post('/cached')->assertOk()->assertHeaderMissing('X-Cache');
});

test('cache.response does not store private or failed responses', function () {
    get('/private', fn () => response('mine', 200, ['Cache-Control' => 'private']), null, ['cache.response:60']);
    get('/broken', fn () => response('down', 503), null, ['cache.response:60']);

    http_get('/private')->assertHeader('X-Cache', 'MISS');
    http_get('/private')->assertHeader('X-Cache', 'MISS');

    http_get('/broken')->assertStatus(503);
    http_get('/broken')->assertStatus(503)->assertHeader('X-Cache', 'MISS');
});

test('http_cache_control and http_etag_matches helpers', function () {
    assert_same('public, max-age=60, s-maxage=600, immutable', http_cache_control(['public', 'max_age=60', 'etag', 's_maxage=600', 'immutable']));
    assert_same('', http_cache_control(['etag']));

    assert_true(http_etag_matches('"a", "b"', '"b"'));
    assert_true(http_etag_matches('W/"a"', '"a"'));
    assert_false(http_etag_matches('"a"', '"b"'));
});
