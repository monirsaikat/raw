<?php

test('select, where, order, limit and offset compile with bound values', function () {
    $q = Database::table('users')
        ->select('id', 'name')
        ->where('active', 1)
        ->where('age', '>=', 18)
        ->orderBy('name')
        ->limit(10)
        ->offset(20);

    assert_same(
        'SELECT `id`, `name` FROM `users` WHERE `active` = ? AND `age` >= ? ORDER BY `name` ASC LIMIT 10 OFFSET 20',
        $q->toSql()
    );
    assert_same([1, 18], $q->getBindings());
});

test('or-where, where-in, null, between and nested groups', function () {
    $q = Database::table('posts')
        ->where('status', 'published')
        ->orWhere(function (QueryBuilder $q) {
            $q->where('author_id', 5)->whereIn('tag', ['a', 'b']);
        })
        ->whereNull('deleted_at')
        ->whereBetween('views', [1, 100]);

    assert_same(
        'SELECT * FROM `posts` WHERE `status` = ? OR (`author_id` = ? AND `tag` IN (?, ?)) AND `deleted_at` IS NULL AND `views` BETWEEN ? AND ?',
        $q->toSql()
    );
    assert_same(['published', 5, 'a', 'b', 1, 100], $q->getBindings());
});

test('where with an array adds one clause per key', function () {
    $q = Database::table('t')->where(['a' => 1, 'b' => 'x']);

    assert_same('SELECT * FROM `t` WHERE `a` = ? AND `b` = ?', $q->toSql());
    assert_same([1, 'x'], $q->getBindings());
});

test('null comparisons become IS NULL / IS NOT NULL', function () {
    assert_same('SELECT * FROM `t` WHERE `x` IS NULL', Database::table('t')->where('x', null)->toSql());
    assert_same('SELECT * FROM `t` WHERE `x` IS NOT NULL', Database::table('t')->where('x', '!=', null)->toSql());
    assert_same('SELECT * FROM `t` WHERE `x` IS NOT NULL', Database::table('t')->whereNotNull('x')->toSql());
    assert_same([], Database::table('t')->where('x', null)->getBindings());
});

test('an empty where-in never matches and an empty not-in always does', function () {
    assert_same('SELECT * FROM `t` WHERE 0 = 1', Database::table('t')->whereIn('id', [])->toSql());
    assert_same('SELECT * FROM `t` WHERE 1 = 1', Database::table('t')->whereNotIn('id', [])->toSql());
});

test('joins and qualified or aliased columns are quoted per segment', function () {
    $q = Database::table('users')
        ->select('users.id', 'profiles.bio as about')
        ->join('profiles', 'users.id', '=', 'profiles.user_id')
        ->leftJoin('roles', 'roles.id', '=', 'users.role_id');

    assert_same(
        'SELECT `users`.`id`, `profiles`.`bio` AS `about` FROM `users` '
            . 'INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id` '
            . 'LEFT JOIN `roles` ON `roles`.`id` = `users`.`role_id`',
        $q->toSql()
    );
});

test('group by and multiple orders', function () {
    $q = Database::table('orders')->select('customer_id')->groupBy('customer_id')->orderByDesc('customer_id')->latest();

    assert_same(
        'SELECT `customer_id` FROM `orders` GROUP BY `customer_id` ORDER BY `customer_id` DESC, `created_at` DESC',
        $q->toSql()
    );
});

test('identifiers, operators and directions are validated', function () {
    assert_throws(fn () => Database::table('users; DROP TABLE x'), InvalidArgumentException::class, 'identifier');
    assert_throws(fn () => Database::table('t')->where('name = 1 OR 1', 1), InvalidArgumentException::class, 'identifier');
    assert_throws(fn () => Database::table('t')->where('a', 'LIKEISH', 1), InvalidArgumentException::class, 'operator');
    assert_throws(fn () => Database::table('t')->orderBy('a', 'sideways'), InvalidArgumentException::class, 'direction');
    assert_throws(fn () => Database::table('t')->join('x', 'a', '=', 'b', 'OUTER'), InvalidArgumentException::class, 'join');
});

test('insert compiles single and multi-row statements', function () {
    [$sql, $bindings] = Database::table('t')->compileInsert(['a' => 1, 'b' => 'x']);
    assert_same('INSERT INTO `t` (`a`, `b`) VALUES (?, ?)', $sql);
    assert_same([1, 'x'], $bindings);

    [$sql, $bindings] = Database::table('t')->compileInsert([['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']]);
    assert_same('INSERT INTO `t` (`a`, `b`) VALUES (?, ?), (?, ?)', $sql);
    assert_same([1, 'x', 2, 'y'], $bindings);

    assert_throws(fn () => Database::table('t')->compileInsert([]), InvalidArgumentException::class);
});

test('update and delete honour where clauses and bind set values first', function () {
    [$sql, $bindings] = Database::table('users')->where('id', 3)->compileUpdate(['name' => 'X', 'age' => 30]);
    assert_same('UPDATE `users` SET `name` = ?, `age` = ? WHERE `id` = ?', $sql);
    assert_same(['X', 30, 3], $bindings);

    [$sql, $bindings] = Database::table('users')->where('id', 3)->limit(1)->compileDelete();
    assert_same('DELETE FROM `users` WHERE `id` = ? LIMIT 1', $sql);
    assert_same([3], $bindings);
});

test('raw expressions bypass quoting and binding', function () {
    assert_same('SELECT COUNT(*) AS c FROM `t`', Database::table('t')->selectRaw('COUNT(*) AS c')->toSql());

    $q = Database::table('t')->where('updated_at', '<', Database::raw('NOW()'));
    assert_same('SELECT * FROM `t` WHERE `updated_at` < NOW()', $q->toSql());
    assert_same([], $q->getBindings());

    $q = Database::table('t')->whereRaw('YEAR(created_at) = ?', [2026]);
    assert_same('SELECT * FROM `t` WHERE (YEAR(created_at) = ?)', $q->toSql());
    assert_same([2026], $q->getBindings());

    [$sql, $bindings] = Database::table('t')->where('id', 1)->compileUpdate(['hits' => Database::raw('`hits` + 1')]);
    assert_same('UPDATE `t` SET `hits` = `hits` + 1 WHERE `id` = ?', $sql);
    assert_same([1], $bindings);
});

test('forPage computes the offset', function () {
    assert_same('SELECT * FROM `t` LIMIT 10 OFFSET 20', Database::table('t')->forPage(3, 10)->toSql());
    assert_same('SELECT * FROM `t` LIMIT 10 OFFSET 0', Database::table('t')->forPage(0, 10)->toSql());
});
