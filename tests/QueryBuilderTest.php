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

test('join closures accept several ON conditions and bound WHERE conditions', function () {
    $q = Database::table('users')->join('profiles', function (JoinClause $join) {
        $join->on('users.id', '=', 'profiles.user_id')->orOn('users.alt_id', '=', 'profiles.user_id')->where('profiles.active', 1);
    })->crossJoin('settings')->where('users.id', 3);

    assert_same(
        'SELECT * FROM `users` INNER JOIN `profiles` ON `users`.`id` = `profiles`.`user_id` OR `users`.`alt_id` = `profiles`.`user_id` AND `profiles`.`active` = ? CROSS JOIN `settings` WHERE `users`.`id` = ?',
        $q->toSql()
    );
    assert_same([1, 3], $q->getBindings(), 'join bindings come before where bindings');
});

test('group by, having and multiple orders', function () {
    $q = Database::table('orders')
        ->select('customer_id')
        ->selectRaw('SUM(total) AS spent')
        ->groupBy('customer_id')
        ->having('spent', '>', 100)
        ->havingRaw('COUNT(*) > ?', [2])
        ->orderByDesc('spent')
        ->latest();

    assert_same(
        'SELECT `customer_id`, SUM(total) AS spent FROM `orders` GROUP BY `customer_id` HAVING `spent` > ? AND COUNT(*) > ? ORDER BY `spent` DESC, `created_at` DESC',
        $q->toSql()
    );
    assert_same([100, 2], $q->getBindings());
});

test('distinct, table aliases, whereColumn, whereNot and like helpers', function () {
    $q = Database::table('users as u')
        ->distinct()
        ->select('u.name')
        ->whereColumn('u.updated_at', '>', 'u.created_at')
        ->whereNot('u.role', 'admin')
        ->whereLike('u.name', 'A%')
        ->orWhereLike('u.email', '%@x.io');

    assert_same(
        'SELECT DISTINCT `u`.`name` FROM `users` AS `u` WHERE `u`.`updated_at` > `u`.`created_at` AND NOT (`u`.`role` = ?) AND `u`.`name` LIKE ? OR `u`.`email` LIKE ?',
        $q->toSql()
    );
    assert_same(['admin', 'A%', '%@x.io'], $q->getBindings());
    assert_same('u', $q->tableAlias());
    assert_same('u.id', $q->qualifyColumn('id'));
});

test('sub-queries: whereIn, whereExists, selectSub and comparison to a sub-select', function () {
    $q = Database::table('users')
        ->selectSub(fn (QueryBuilder $s) => $s->from('posts')->selectRaw('COUNT(*)')->whereColumn('posts.user_id', 'users.id'), 'posts_count')
        ->whereIn('id', fn (QueryBuilder $s) => $s->from('admins')->select('user_id')->where('level', '>', 1))
        ->whereExists(fn (QueryBuilder $s) => $s->from('logins')->select(new Raw('1'))->whereColumn('logins.user_id', 'users.id'))
        ->where('created_at', '>=', Database::table('settings')->select('since')->where('key', 'cutoff'));

    assert_same(
        'SELECT *, (SELECT COUNT(*) FROM `posts` WHERE `posts`.`user_id` = `users`.`id`) AS `posts_count` FROM `users` '
            . 'WHERE `id` IN (SELECT `user_id` FROM `admins` WHERE `level` > ?) '
            . 'AND EXISTS (SELECT 1 FROM `logins` WHERE `logins`.`user_id` = `users`.`id`) '
            . 'AND `created_at` >= (SELECT `since` FROM `settings` WHERE `key` = ?)',
        $q->toSql()
    );
    assert_same([1, 'cutoff'], $q->getBindings());
});

test('date helpers are driver aware', function () {
    $mysql = Database::table('t')->whereDate('created_at', '2026-09-07')->whereYear('created_at', '>=', 2020)->whereMonth('created_at', 9);
    assert_same('SELECT * FROM `t` WHERE DATE(`created_at`) = ? AND YEAR(`created_at`) >= ? AND MONTH(`created_at`) = ?', $mysql->toSql());
    assert_same(['2026-09-07', 2020, 9], $mysql->getBindings());

    $sqlite = Database::table('t', 'testing')->whereDate('created_at', new DateTimeValue('2026-09-07 10:00'))->whereYear('created_at', 2026);
    assert_same("SELECT * FROM `t` WHERE DATE(`created_at`) = ? AND CAST(strftime('%Y', `created_at`) AS INTEGER) = ?", $sqlite->toSql());
    assert_same(['2026-09-07', 2026], $sqlite->getBindings());
});

test('unions, locks and random ordering', function () {
    $q = Database::table('a')->select('id')->where('x', 1)->union(Database::table('b')->select('id')->where('y', 2))->orderBy('id')->lockForUpdate();
    assert_same('(SELECT `id` FROM `a` WHERE `x` = ?) UNION (SELECT `id` FROM `b` WHERE `y` = ?) ORDER BY `id` ASC FOR UPDATE', $q->toSql());
    assert_same([1, 2], $q->getBindings());

    $sqlite = Database::table('a', 'testing')->unionAll(fn (QueryBuilder $s) => $s->from('b'))->inRandomOrder();
    assert_same('SELECT * FROM `a` UNION ALL SELECT * FROM `b` ORDER BY RANDOM()', $sqlite->toSql());
    assert_same('SELECT * FROM `t` ORDER BY RAND()', Database::table('t')->inRandomOrder()->toSql());
    assert_same('SELECT * FROM `t` LOCK IN SHARE MODE', Database::table('t')->sharedLock()->toSql());
});

test('conditional clauses with when/unless', function () {
    $search = 'ann';
    $q = Database::table('users')
        ->when($search, fn (QueryBuilder $q, $value) => $q->whereLike('name', "%$value%"))
        ->when(null, fn (QueryBuilder $q) => $q->where('never', 1), fn (QueryBuilder $q) => $q->where('fallback', 1))
        ->unless(false, fn (QueryBuilder $q) => $q->orderBy('name'));

    assert_same('SELECT * FROM `users` WHERE `name` LIKE ? AND `fallback` = ? ORDER BY `name` ASC', $q->toSql());
});

test('identifiers, operators and directions are validated', function () {
    assert_throws(fn () => Database::table('users; DROP TABLE x'), InvalidArgumentException::class, 'identifier');
    assert_throws(fn () => Database::table('t')->where('name = 1 OR 1', 1), InvalidArgumentException::class, 'identifier');
    assert_throws(fn () => Database::table('t')->where('a', 'LIKEISH', 1), InvalidArgumentException::class, 'operator');
    assert_throws(fn () => Database::table('t')->orderBy('a', 'sideways'), InvalidArgumentException::class, 'direction');
    assert_throws(fn () => Database::table('t')->join('x', 'a', '=', 'b', 'OUTER'), InvalidArgumentException::class, 'join');
    assert_throws(fn () => Database::table('t')->with('x'), LogicException::class, 'no model');
    assert_throws(fn () => Database::table('t')->nonsense(), BadMethodCallException::class);
});

test('insert compiles single and multi-row statements, ignore variants per driver', function () {
    [$sql, $bindings] = Database::table('t')->compileInsert(['a' => 1, 'b' => 'x']);
    assert_same('INSERT INTO `t` (`a`, `b`) VALUES (?, ?)', $sql);
    assert_same([1, 'x'], $bindings);

    [$sql, $bindings] = Database::table('t')->compileInsert([['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'y']]);
    assert_same('INSERT INTO `t` (`a`, `b`) VALUES (?, ?), (?, ?)', $sql);
    assert_same([1, 'x', 2, 'y'], $bindings);

    assert_same('INSERT IGNORE INTO `t` (`a`) VALUES (?)', Database::table('t')->compileInsert(['a' => 1], true)[0]);
    assert_same('INSERT OR IGNORE INTO `t` (`a`) VALUES (?)', Database::table('t', 'testing')->compileInsert(['a' => 1], true)[0]);

    assert_throws(fn () => Database::table('t')->compileInsert([]), InvalidArgumentException::class);
});

test('upsert compiles for MySQL and SQLite', function () {
    $rows = [['email' => 'a@x', 'name' => 'A', 'hits' => 1], ['email' => 'b@x', 'name' => 'B', 'hits' => 2]];

    [$sql, $bindings] = Database::table('t')->compileUpsert($rows, 'email');
    assert_same('INSERT INTO `t` (`email`, `name`, `hits`) VALUES (?, ?, ?), (?, ?, ?) ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `name` = VALUES(`name`), `hits` = VALUES(`hits`)', $sql);
    assert_same(['a@x', 'A', 1, 'b@x', 'B', 2], $bindings);

    [$sql] = Database::table('t')->compileUpsert($rows, 'email', ['hits']);
    assert_same('INSERT INTO `t` (`email`, `name`, `hits`) VALUES (?, ?, ?), (?, ?, ?) ON DUPLICATE KEY UPDATE `hits` = VALUES(`hits`)', $sql);

    [$sql] = Database::table('t', 'testing')->compileUpsert($rows, ['email'], ['hits']);
    assert_same('INSERT INTO `t` (`email`, `name`, `hits`) VALUES (?, ?, ?), (?, ?, ?) ON CONFLICT (`email`) DO UPDATE SET `hits` = excluded.`hits`', $sql);
});

test('update and delete honour where clauses and bind set values first', function () {
    [$sql, $bindings] = Database::table('users')->where('id', 3)->compileUpdate(['name' => 'X', 'age' => 30]);
    assert_same('UPDATE `users` SET `name` = ?, `age` = ? WHERE `id` = ?', $sql);
    assert_same(['X', 30, 3], $bindings);

    [$sql, $bindings] = Database::table('users')->where('id', 3)->limit(1)->compileDelete();
    assert_same('DELETE FROM `users` WHERE `id` = ? LIMIT 1', $sql);
    assert_same([3], $bindings);

    [$sql] = Database::table('users', 'testing')->where('id', 3)->limit(1)->compileDelete();
    assert_same('DELETE FROM `users` WHERE `id` = ?', $sql, 'SQLite has no LIMIT on DELETE');
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

test('forPage, take/skip and toRawSql', function () {
    assert_same('SELECT * FROM `t` LIMIT 10 OFFSET 20', Database::table('t')->forPage(3, 10)->toSql());
    assert_same('SELECT * FROM `t` LIMIT 5 OFFSET 2', Database::table('t')->skip(2)->take(5)->toSql());
    assert_same(
        "SELECT * FROM `t` WHERE `name` = 'O''Neil' AND `age` > 30 AND `active` = 1 AND `x` IS NULL",
        Database::table('t')->where('name', "O'Neil")->where('age', '>', 30)->where('active', true)->whereNull('x')->toRawSql()
    );
});
