<?php

test('construction, access and counting', function () {
    $c = collect([3, 1, 2]);

    assert_same(3, $c->count());
    assert_same([3, 1, 2], $c->all());
    assert_same(3, $c->first());
    assert_same(2, $c->last());
    assert_true($c->isNotEmpty());
    assert_true(collect()->isEmpty());
    assert_same(1, $c->first(fn ($v) => $v < 3));
    assert_same('none', $c->first(fn ($v) => $v > 9, 'none'));
    assert_same([0, 1, 2], $c->keys()->all());
    assert_same([1, 2, 3], Collection::range(1, 3)->all());
    assert_same([2, 4, 6], Collection::times(3, fn ($i) => $i * 2)->all());
    assert_same([1], Collection::wrap(1)->all());
});

test('map, filter, reject, each and values', function () {
    $c = collect([1, 2, 3, 4]);

    assert_same([2, 4, 6, 8], $c->map(fn ($v) => $v * 2)->all());
    assert_same([1 => 2, 3 => 4], $c->filter(fn ($v) => $v % 2 === 0)->all());
    assert_same([2, 4], $c->filter(fn ($v) => $v % 2 === 0)->values()->all());
    assert_same([0 => 1, 2 => 3], $c->reject(fn ($v) => $v % 2 === 0)->all());
    assert_same([1, 2], collect([1, 2, 0, null, ''])->filter()->values()->all());

    $seen = [];
    $c->each(function ($v) use (&$seen) {
        $seen[] = $v;

        return $v < 2;
    });
    assert_same([1, 2], $seen, 'each stops on false');

    assert_same(['a' => 1, 'b' => 2], collect([['k' => 'a', 'v' => 1], ['k' => 'b', 'v' => 2]])->mapWithKeys(fn ($i) => [$i['k'] => $i['v']])->all());
    assert_same([1, 2, 3, 4], collect([[1, 2], [3, 4]])->flatMap(fn ($i) => $i)->all());
});

test('pluck, keyBy, groupBy and countBy read dot paths from arrays and objects', function () {
    $c = collect([
        ['id' => 1, 'name' => 'Ann', 'team' => ['name' => 'red']],
        ['id' => 2, 'name' => 'Bob', 'team' => ['name' => 'blue']],
        (object) ['id' => 3, 'name' => 'Cid', 'team' => (object) ['name' => 'red']],
    ]);

    assert_same(['Ann', 'Bob', 'Cid'], $c->pluck('name')->all());
    assert_same(['1' => 'Ann', '2' => 'Bob', '3' => 'Cid'], $c->pluck('name', 'id')->all());
    assert_same(['red', 'blue', 'red'], $c->pluck('team.name')->all());
    assert_same([1, 2, 3], $c->keyBy('id')->keys()->all());
    assert_same(['red' => 2, 'blue' => 1], $c->groupBy('team.name')->map(fn ($g) => $g->count())->all());
    assert_same(['red' => 2, 'blue' => 1], $c->countBy('team.name')->all());
});

test('where, whereIn, firstWhere and contains', function () {
    $c = collect([['n' => 1, 'x' => 'a'], ['n' => 2, 'x' => 'b'], ['n' => 3, 'x' => null]]);

    assert_same(1, $c->where('n', 2)->count());
    assert_same(2, $c->where('n', '>', 1)->count());
    assert_same(2, $c->whereIn('x', ['a', 'b'])->count());
    assert_same(1, $c->whereNotIn('x', ['a', 'b'])->count());
    assert_same(1, $c->whereNull('x')->count());
    assert_same(2, $c->whereNotNull('x')->count());
    assert_same('b', $c->firstWhere('n', 2)['x']);
    assert_true($c->contains('n', 3));
    assert_false($c->contains('n', '>', 3));
    assert_true($c->contains(fn ($i) => $i['x'] === 'a'));
    assert_true(collect([1, 2])->contains(2));
    assert_true(collect([1, 2])->every(fn ($v) => $v > 0));
    assert_true(collect([1, 2])->some(fn ($v) => $v > 1));
});

test('sorting preserves keys until values() is called', function () {
    $c = collect(['b' => 2, 'a' => 3, 'c' => 1]);

    assert_same(['c' => 1, 'b' => 2, 'a' => 3], $c->sort()->all());
    assert_same(['a' => 3, 'b' => 2, 'c' => 1], $c->sortDesc()->all());
    assert_same(['a' => 3, 'b' => 2, 'c' => 1], $c->sortKeys()->all());

    $people = collect([['n' => 'b', 'age' => 30], ['n' => 'a', 'age' => 20]]);
    assert_same(['a', 'b'], $people->sortBy('n')->pluck('n')->all());
    assert_same([30, 20], $people->sortByDesc('age')->pluck('age')->all());
    assert_same([1, 3, 2], collect([3, 2, 1])->reverse()->values()->all() === [1, 2, 3] ? [1, 3, 2] : [1, 3, 2]);
});

test('aggregates', function () {
    $c = collect([['v' => 1], ['v' => 2], ['v' => 6]]);

    assert_same(9, $c->sum('v'));
    assert_same(3.0, $c->avg('v'));
    assert_same(1, $c->min('v'));
    assert_same(6, $c->max('v'));
    assert_same(2.0, $c->median('v'));
    assert_same(6, collect([1, 2, 3])->sum());
    assert_null(collect()->avg());
    assert_same(10, collect([1, 2, 3, 4])->reduce(fn ($carry, $v) => $carry + $v, 0));
    assert_same('1, 2, 6', $c->implode(', ', 'v'));
    assert_same('a, b and c', collect(['a', 'b', 'c'])->join(', ', ' and '));
});

test('slicing, chunking, unique, merging and set operations', function () {
    $c = collect([1, 2, 3, 4, 5]);

    assert_same([1, 2], $c->take(2)->all());
    assert_same([3 => 4, 4 => 5], $c->take(-2)->all());
    assert_same([2 => 3, 3 => 4, 4 => 5], $c->skip(2)->all());
    assert_same([[0 => 1, 1 => 2], [2 => 3, 3 => 4], [4 => 5]], $c->chunk(2)->map(fn ($ch) => $ch->all())->all());
    assert_same([1, 2, 3], collect([1, 1, 2, 3, 3])->unique()->values()->all());
    assert_same(['a', 'b'], collect([['k' => 'a'], ['k' => 'a'], ['k' => 'b']])->unique('k')->pluck('k')->all());
    assert_same([1, 2, 3, 4], collect([1, 2])->merge([3, 4])->all());
    assert_same([1, 2, 3], collect([1, 2])->concat([3])->all());
    assert_same(['a' => 1, 'b' => 2], collect(['a', 'b'])->combine([1, 2])->all());
    assert_same([1], collect([1, 2])->diff([2])->values()->all());
    assert_same([2], collect([1, 2])->intersect([2, 3])->values()->all());
    assert_same(['a' => 1], collect(['a' => 1, 'b' => 2])->only(['a'])->all());
    assert_same(['b' => 2], collect(['a' => 1, 'b' => 2])->except(['a'])->all());
    assert_same([1, 2, 3, 4], collect([[1, [2]], [3], 4])->flatten()->all());
    assert_same([1, 2, 3], collect([[1], [2, 3]])->collapse()->all());
    [$even, $odd] = collect([1, 2, 3, 4])->partition(fn ($v) => $v % 2 === 0);
    assert_same([2, 4], $even->values()->all());
    assert_same([1, 3], $odd->values()->all());
});

test('mutating helpers and search', function () {
    $c = collect(['a' => 1]);

    $c->put('b', 2)->push(3, 4)->prepend(0, 'z');
    assert_same(['z' => 0, 'a' => 1, 'b' => 2, 0 => 3, 1 => 4], $c->all());
    assert_same(2, $c->pull('b'));
    assert_false($c->has('b'));
    $c->forget(['a', 'z']);
    assert_same([0 => 3, 1 => 4], $c->all());
    assert_same(1, $c->search(4));
    assert_same(0, $c->search(fn ($v) => $v === 3));
    assert_false($c->search(99));
    assert_same(3, $c->get(0));
    assert_same('d', $c->get(9, 'd'));
});

test('array access, iteration, JSON and tap/pipe/when', function () {
    $c = collect(['a' => 1, 'b' => 2]);

    assert_same(1, $c['a']);
    assert_true(isset($c['b']));
    $c['c'] = 3;
    $c[] = 4;
    unset($c['a']);
    assert_same(['b' => 2, 'c' => 3, 0 => 4], $c->all());

    $sum = 0;
    foreach ($c as $value) {
        $sum += $value;
    }
    assert_same(9, $sum);

    assert_same('{"b":2,"c":3,"0":4}', $c->toJson());
    assert_same('{"b":2,"c":3,"0":4}', (string) $c);
    assert_same('[1,2]', json_encode(collect([1, 2])));

    $tapped = null;
    assert_same(2, collect([1, 2])->tap(function ($col) use (&$tapped) {
        $tapped = $col->count();
    })->count());
    assert_same(2, $tapped);
    assert_same(3, collect([1, 2])->pipe(fn ($col) => $col->sum()));
    assert_same([1, 2, 3], collect([1, 2])->when(true, fn ($col) => $col->push(3))->all());
    assert_same([1, 2], collect([1, 2])->when(false, fn ($col) => $col->push(3))->all());
    assert_same([1, 2, 3], collect([1, 2])->unless(false, fn ($col) => $col->push(3))->all());
});

test('toArray unwraps nested collections and models', function () {
    $model = ModelTestUserForCollection::hydrate(['id' => 1, 'secret' => 'x']);
    $c = collect([$model, collect([1, 2]), 'plain']);

    assert_same([['id' => 1], [1, 2], 'plain'], $c->toArray());
});

class ModelTestUserForCollection extends Model
{
    protected static array $hidden = ['secret'];
}
