<?php

class ModelTestUser extends Model
{
    protected static string $table = 'users';
    protected static array $fillable = ['name', 'email'];
    protected static array $hidden = ['password'];
}

class TestCategory extends Model
{
}

class TestBox extends Model
{
}

class TestStatus extends Model
{
}

test('table names derive from the class name unless declared', function () {
    assert_same('users', ModelTestUser::table());
    assert_same('test_categories', TestCategory::table());
    assert_same('test_boxes', TestBox::table());
    assert_same('test_statuses', TestStatus::table());
});

test('fill respects fillable; forceFill and property sets do not', function () {
    $user = new ModelTestUser(['name' => 'Ann', 'email' => 'a@x.io', 'is_admin' => 1]);

    assert_same(['name' => 'Ann', 'email' => 'a@x.io'], $user->getAttributes());

    $user->forceFill(['is_admin' => 1]);
    assert_same(1, $user->is_admin);

    $user->role = 'editor';
    assert_same('editor', $user['role']);
});

test('guarded is used when fillable is empty', function () {
    $category = new TestCategory(['id' => 9, 'name' => 'Books']);

    assert_same(['name' => 'Books'], $category->getAttributes());
});

test('hydrate marks the model as existing and clean; changes are tracked', function () {
    $user = ModelTestUser::hydrate(['id' => 1, 'name' => 'Ann', 'email' => 'a@x.io']);

    assert_true($user->exists);
    assert_false($user->isDirty());
    assert_same(1, $user->getKey());

    $user->name = 'Anna';

    assert_true($user->isDirty());
    assert_true($user->isDirty('name'));
    assert_false($user->isDirty('email'));
    assert_same(['name' => 'Anna'], $user->getDirty());
    assert_same('Ann', $user->getOriginal('name'));
});

test('array access, isset and unset work on attributes', function () {
    $user = ModelTestUser::hydrate(['id' => 1, 'name' => 'Ann']);

    assert_same('Ann', $user['name']);
    assert_true(isset($user['name']));
    assert_false(isset($user['missing']));
    assert_null($user['missing']);

    unset($user['name']);
    assert_false(isset($user->name));
});

test('hidden attributes are left out of toArray and JSON', function () {
    $user = ModelTestUser::hydrate(['id' => 1, 'name' => 'Ann', 'password' => 'hash']);

    assert_same(['id' => 1, 'name' => 'Ann'], $user->toArray());
    assert_same('{"id":1,"name":"Ann"}', json_encode($user));
    assert_same('{"id":1,"name":"Ann"}', $user->toJson());
    assert_same('hash', $user->password, 'still readable directly');
});

test('static calls forward to a model-aware query builder', function () {
    $q = ModelTestUser::where('email', 'a@x.io')->orderBy('id');

    assert_true($q instanceof QueryBuilder);
    assert_same('SELECT * FROM `users` WHERE `email` = ? ORDER BY `id` ASC', $q->toSql());
});

test('find with an empty id returns null without querying', function () {
    assert_null(ModelTestUser::find(null));
    assert_null(ModelTestUser::find(''));
});
