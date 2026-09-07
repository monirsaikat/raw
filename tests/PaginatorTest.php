<?php

test('paginator reports totals, pages and item ranges', function () {
    $p = new Paginator(['a', 'b', 'c'], 23, 3, 2, ['path' => '/items', 'query' => ['q' => 'x']]);

    assert_same(23, $p->total());
    assert_same(3, $p->perPage());
    assert_same(2, $p->currentPage());
    assert_same(8, $p->lastPage());
    assert_same(4, $p->firstItem());
    assert_same(6, $p->lastItem());
    assert_same(3, $p->count());
    assert_true($p->hasPages());
    assert_true($p->hasMorePages());
    assert_false($p->onFirstPage());
    assert_false($p->onLastPage());
    assert_same('/items?q=x&page=3', $p->nextPageUrl());
    assert_same('/items?q=x&page=1', $p->previousPageUrl());
    assert_same('/items?q=x&page=8', $p->url(8));
});

test('single page has no links; first and last pages disable arrows', function () {
    $single = new Paginator(['a'], 1, 10, 1, ['path' => '/x']);
    assert_false($single->hasPages());
    assert_same('', $single->links());
    assert_null($single->nextPageUrl());

    $first = new Paginator(['a'], 30, 10, 1, ['path' => '/x']);
    assert_contains('page-item disabled"><span class="page-link" aria-hidden="true">&laquo;', $first->links());
    assert_contains('rel="next"', $first->links());
    assert_contains('page-item active', $first->links());

    $last = new Paginator(['a'], 30, 10, 3, ['path' => '/x']);
    assert_contains('rel="prev"', $last->links());
    assert_not_contains('rel="next"', $last->links());
    assert_true($last->onLastPage());
});

test('elements window around the current page with ellipses', function () {
    $p = new Paginator([], 200, 10, 10, ['path' => '/x']);

    assert_same([1, null, 8, 9, 10, 11, 12, null, 20], $p->elements(2));
    assert_same([1, 2, 3, 4, 5, null, 20], (new Paginator([], 200, 10, 3, ['path' => '/x']))->elements(2));
    assert_same([1, null, 16, 17, 18, 19, 20], (new Paginator([], 200, 10, 18, ['path' => '/x']))->elements(2));
});

test('appends and query strings are carried on links, page param excluded', function () {
    $p = new Paginator([], 50, 10, 2, ['path' => '/x', 'query' => ['page' => '2', 'sort' => 'name']]);

    assert_same('/x?sort=name&page=3', $p->nextPageUrl());
    $p->appends(['filter' => 'a b']);
    assert_same('/x?sort=name&filter=a+b&page=3', $p->nextPageUrl());
    assert_contains('href="/x?sort=name&amp;filter=a+b&amp;page=3"', $p->links());
    $p->withPath('/y')->setPageName('p');
    assert_same('/y?sort=name&filter=a+b&p=3', $p->nextPageUrl());
});

test('simple paginator only knows about a next page', function () {
    $p = Paginator::simple(['a', 'b'], 2, 1, true, ['path' => '/x']);

    assert_null($p->total());
    assert_null($p->lastPage());
    assert_true($p->hasMorePages());
    assert_same('/x?page=2', $p->nextPageUrl());
    assert_same([], $p->elements());
    assert_contains('rel="next"', $p->links());
});

test('serialisation, iteration and collection forwarding', function () {
    $p = new Paginator([['id' => 1], ['id' => 2]], 2, 10, 1, ['path' => '/x']);

    $array = $p->toArray();
    assert_same([['id' => 1], ['id' => 2]], $array['data']);
    assert_same(2, $array['total']);
    assert_same('/x?page=1', $array['first_page_url']);
    assert_null($array['next_page_url']);
    assert_same($array, json_decode(json_encode($p), true));

    $ids = [];
    foreach ($p as $item) {
        $ids[] = $item['id'];
    }
    assert_same([1, 2], $ids);
    assert_same([1, 2], $p->pluck('id')->all());
    assert_same(['id' => 1], $p[0]);
    assert_same(2, count($p));
});
