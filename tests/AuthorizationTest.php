<?php

class AzUser extends Model
{
    use Authorizable;

    protected static string $table = 'az_users';
    protected static array $guarded = [];
}

class AzPost extends Model
{
    protected static string $table = 'az_posts';
    protected static array $guarded = [];
}

class AzComment extends Model
{
    protected static array $guarded = [];
}

class AzAudit
{
}

// Found by convention for AzPost.
class AzPostPolicy
{
    public function before(AzUser $user, string $ability): ?bool
    {
        return $user->role === 'admin' ? true : null;
    }

    public function view(?AzUser $user, AzPost $post): bool
    {
        return (bool) $post->published || ($user !== null && $user->id === $post->user_id);
    }

    public function update(AzUser $user, AzPost $post): bool
    {
        return $user->id === $post->user_id;
    }

    public function delete(AzUser $user, AzPost $post): bool
    {
        return false;
    }

    public function create(AzUser $user): bool
    {
        return true;
    }
}

class AzCommentPolicy
{
    public function __construct(public AzAudit $audit)
    {
    }

    public function update(AzUser $user, AzComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}

class AzAbilities
{
    public function editor(AzUser $user): bool
    {
        return $user->role === 'editor';
    }
}

function az_user(int $id, string $role = 'member'): AzUser
{
    return AzUser::hydrate(['id' => $id, 'role' => $role, 'name' => "user$id"]);
}

function az_post(int $userId, bool $published = false): AzPost
{
    return AzPost::hydrate(['id' => 1, 'user_id' => $userId, 'published' => $published]);
}

test('policies are discovered by naming convention and answer abilities', function () {
    $owner = az_user(1);
    $other = az_user(2);
    $post = az_post(1);

    assert_true($owner->can('update', $post));
    assert_false($other->can('update', $post));
    assert_true($other->cannot('update', $post));
    assert_false($owner->can('delete', $post));
    assert_true($owner->can('create', AzPost::class));
    assert_true(gate()->forUser($other)->allows('view', az_post(1, true)));
    assert_false(gate()->forUser($other)->allows('view', $post));
    assert_false($owner->can('nonexistent', $post), 'a missing policy method denies');
    assert_instance_of(AzPostPolicy::class, gate()->getPolicyFor($post));
    assert_null(gate()->getPolicyFor(new stdClass()));
});

test('policy before() short-circuits and guests only reach nullable callbacks', function () {
    $admin = az_user(9, 'admin');
    $post = az_post(1);

    assert_true($admin->can('delete', $post));
    assert_true($admin->can('anything', $post));

    $guest = gate()->forUser(null);
    assert_true($guest->allows('view', az_post(1, true)), 'view() accepts ?AzUser');
    assert_false($guest->allows('update', $post), 'update() needs a user');
});

test('gate definitions, before/after hooks, any() and check()', function () {
    gate_define('admin', fn (AzUser $user) => $user->role === 'admin');
    gate_define('public', fn (?AzUser $user) => true);
    gate_define('by-string', 'AzAbilities@editor');

    assert_true(gate()->has('admin'));
    assert_true(gate()->forUser(az_user(1, 'admin'))->allows('admin'));
    assert_false(gate()->forUser(az_user(1))->allows('admin'));
    assert_true(gate()->forUser(null)->allows('public'));
    assert_false(gate()->forUser(null)->allows('admin'));
    assert_false(gate()->forUser(az_user(1))->allows('undefined'));
    assert_true(gate()->forUser(az_user(1, 'editor'))->allows('by-string'));
    assert_true(gate()->forUser(az_user(1))->any(['admin', 'public']));
    assert_false(gate()->forUser(az_user(1))->check(['admin', 'public']));
    assert_true(gate()->forUser(az_user(1))->none(['admin', 'undefined']));

    gate_before(fn (?AzUser $user, string $ability) => $ability === 'always' ? true : null);
    assert_true(gate()->forUser(null)->allows('always'));

    gate_after(fn (?AzUser $user, string $ability, ?bool $result) => $ability === 'fallback' ? true : null);
    assert_true(gate()->forUser(az_user(1))->allows('fallback'));
    assert_false(gate()->forUser(az_user(1))->allows('admin'), 'after() cannot override a decision');
});

test('the logged-in user is used by default and authorize() throws a 403', function () {
    gate_define('admin', fn (AzUser $user) => $user->role === 'admin');

    auth_set_user(az_user(1, 'admin'));
    assert_true(can('admin'));
    assert_false(cannot('admin'));
    authorize('admin');

    auth_set_user(az_user(2));
    assert_false(can('admin'));
    assert_false(can_any(['admin', 'nothing']));

    $e = assert_throws(fn () => authorize('admin'), AuthorizationException::class);
    assert_same(403, $e->status);
    assert_same(403, render_exception($e)->status);

    auth_set_user(null);
    assert_false(can('admin'));
});

test('explicit policy registration, config policies and container-built policies', function () {
    gate_policy(AzComment::class, AzCommentPolicy::class);
    $comment = AzComment::hydrate(['id' => 1, 'user_id' => 3]);

    assert_true(az_user(3)->can('update', $comment));
    assert_false(az_user(4)->can('update', $comment));
    assert_instance_of(AzCommentPolicy::class, gate()->getPolicyFor($comment));
    assert_instance_of(AzAudit::class, gate()->getPolicyFor($comment)->audit, 'policy dependencies are injected');

    config_set('auth.policies', ['AzComment' => 'AzCommentPolicy']);
    app()->flush();
    container_boot();
    gate_boot();

    assert_same(['AzComment' => 'AzCommentPolicy'], gate()->policies());
});

test('the can modifier works in templates and the can middleware guards routes', function () {
    gate_define('admin', fn (AzUser $user) => $user->role === 'admin');
    auth_set_user(az_user(1, 'admin'));

    $template = View::instance()->smarty()->createTemplate(
        'string:{if "admin"|can}yes{else}no{/if}|{if "update"|can:$post}owner{else}not{/if}|{if "delete"|cannot:$post}nodelete{/if}'
    );
    $template->assign('post', az_post(1));
    assert_same('yes|owner|', $template->fetch(), 'admin before() allows everything');

    get('/admin', fn () => 'admin area', null, ['can:admin']);
    get('/posts/{id}/edit', fn ($id) => "edit $id", null, ['can:create,AzPost']);

    assert_same('admin area', dispatch('GET', '/admin'));
    assert_same('edit 3', dispatch('GET', '/posts/3/edit'));

    auth_set_user(az_user(2));
    $e = assert_throws(fn () => dispatch('GET', '/admin'), AuthorizationException::class);
    assert_same(403, $e->status);
    assert_same('edit 4', dispatch('GET', '/posts/4/edit'), 'members may create');
});
