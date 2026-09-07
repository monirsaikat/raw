<?php

// Runs against an in-memory SQLite database when the pdo_sqlite driver is
// available, otherwise against the configured MySQL connection using
// it_-prefixed tables that are dropped afterwards. Skipped when neither works.

class ItAuthor extends Model
{
    protected static string $table = 'it_authors';
    protected static array $fillable = ['name', 'email', 'settings', 'active', 'joined_at'];
    protected static array $casts = ['active' => 'bool', 'settings' => 'array', 'joined_at' => 'date'];
    protected static array $appends = ['upper_name'];
    protected static array $hidden = ['email'];

    public function getUpperNameAttribute(): string
    {
        return strtoupper((string) ($this->attributes['name'] ?? ''));
    }

    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = trim((string) $value);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ItPost::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ItProfile::class);
    }

    public function scopeActive(QueryBuilder $query): QueryBuilder
    {
        return $query->where('active', 1);
    }
}

class ItPost extends Model
{
    use SoftDeletes;

    protected static string $table = 'it_posts';
    protected static array $fillable = ['title', 'body', 'it_author_id', 'views', 'published'];
    protected static array $casts = ['views' => 'int', 'published' => 'bool'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(ItAuthor::class, 'it_author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ItTag::class, 'it_post_tag', 'post_id', 'tag_id')->withPivot('note')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ItComment::class, 'post_id');
    }

    public function scopePublished(QueryBuilder $query): void
    {
        $query->where('published', 1);
    }
}

class ItTag extends Model
{
    protected static string $table = 'it_tags';
    protected static array $fillable = ['name'];
    protected static bool $timestamps = false;

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(ItPost::class, 'it_post_tag', 'tag_id', 'post_id');
    }
}

class ItComment extends Model
{
    protected static string $table = 'it_comments';
    protected static array $fillable = ['post_id', 'body'];
    protected static bool $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(ItPost::class, 'post_id');
    }
}

class ItProfile extends Model
{
    protected static string $table = 'it_profiles';
    protected static array $fillable = ['it_author_id', 'bio'];
    protected static bool $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(ItAuthor::class, 'it_author_id');
    }
}

class ItAuthorFactory extends Factory
{
    protected string $model = 'ItAuthor';

    public function definition(): array
    {
        return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'active' => true];
    }
}

class ItPostFactory extends Factory
{
    protected string $model = 'ItPost';

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->title(),
            'body' => fake()->paragraph(),
            'it_author_id' => ItAuthor::factory(),
            'views' => fake()->number(0, 100),
            'published' => false,
        ];
    }
}

class ItObserver
{
    public array $log = [];

    public function creating(ItTag $tag): void
    {
        $this->log[] = 'creating:' . $tag->name;
    }

    public function created(ItTag $tag): void
    {
        $this->log[] = 'created:' . $tag->id;
    }
}

const IT_TABLES = ['it_comments', 'it_post_tag', 'it_posts', 'it_profiles', 'it_tags', 'it_authors'];

// Chooses the connection, creates the schema once and points the default
// connection at it for the current test. Returns the connection name.
function db(): string
{
    static $name = null;
    static $prepared = false;

    if ($name === null) {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            Database::addConnection('integration', ['driver' => 'sqlite', 'database' => ':memory:']);
            $name = 'integration';
        } elseif (env('DB_TEST_CONNECTION')) {
            // An explicitly dedicated test connection; never the app database.
            $name = (string) env('DB_TEST_CONNECTION');
        } else {
            $name = '';
        }
    }

    if ($name === '') {
        skip('pdo_sqlite is not loaded; enable it in php.ini or set DB_TEST_CONNECTION to a throwaway connection');
    }

    config_set('database.default', $name);
    Model::flushState();

    if (!$prepared) {
        $prepared = true;
        db_create_schema($name);
        Database::connection($name)->listen(function () {
            $GLOBALS['__it_queries'] = ($GLOBALS['__it_queries'] ?? 0) + 1;
        });
    }

    db_clean($name);
    $GLOBALS['__it_queries'] = 0;

    return $name;
}

function db_create_schema(string $name): void
{
    foreach (IT_TABLES as $table) {
        Schema::dropIfExists($table, $name);
    }

    Schema::create('it_authors', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->unique();
        $t->boolean('active')->default(true);
        $t->json('settings')->nullable();
        $t->date('joined_at')->nullable();
        $t->timestamps();
    }, $name);

    Schema::create('it_profiles', function (Blueprint $t) {
        $t->id();
        $t->foreignId('it_author_id')->constrained('it_authors')->cascadeOnDelete();
        $t->text('bio')->nullable();
    }, $name);

    Schema::create('it_posts', function (Blueprint $t) {
        $t->id();
        $t->foreignId('it_author_id')->constrained('it_authors')->cascadeOnDelete();
        $t->string('title');
        $t->text('body')->nullable();
        $t->unsignedInteger('views')->default(0);
        $t->boolean('published')->default(false);
        $t->timestamps();
        $t->softDeletes();
    }, $name);

    Schema::create('it_tags', function (Blueprint $t) {
        $t->id();
        $t->string('name')->unique();
    }, $name);

    Schema::create('it_post_tag', function (Blueprint $t) {
        $t->id();
        $t->foreignId('post_id')->constrained('it_posts')->cascadeOnDelete();
        $t->foreignId('tag_id')->constrained('it_tags')->cascadeOnDelete();
        $t->string('note')->nullable();
        $t->timestamps();
        $t->unique(['post_id', 'tag_id']);
    }, $name);

    Schema::create('it_comments', function (Blueprint $t) {
        $t->id();
        $t->foreignId('post_id')->constrained('it_posts')->cascadeOnDelete();
        $t->text('body');
    }, $name);
}

function db_clean(string $name): void
{
    foreach (IT_TABLES as $table) {
        Database::table($table, $name)->delete();
    }
}

function db_queries(): int
{
    return $GLOBALS['__it_queries'] ?? 0;
}

// ------------------------------------------------------------------------

test('schema builder creates tables and can describe them', function () {
    $c = db();

    assert_true(Schema::hasTable('it_posts', $c));
    assert_false(Schema::hasTable('it_nope', $c));
    assert_true(Schema::hasColumn('it_posts', 'deleted_at', $c));
    assert_true(Schema::hasColumns('it_authors', ['name', 'email'], $c));
    assert_same(['id', 'name', 'email', 'active', 'settings', 'joined_at', 'created_at', 'updated_at'], Schema::getColumnListing('it_authors', $c));

    $columns = collect(Schema::getColumns('it_authors', $c))->keyBy('name');
    assert_true($columns['id']['auto_increment']);
    assert_true($columns['settings']['nullable']);
    assert_false($columns['name']['nullable']);
    assert_true(in_array('it_tags', Schema::getTables($c), true));

    Schema::table('it_tags', fn (Blueprint $t) => $t->string('color')->nullable(), $c);
    assert_true(Schema::hasColumn('it_tags', 'color', $c));
    Schema::table('it_tags', fn (Blueprint $t) => $t->dropColumn('color'), $c);
    assert_false(Schema::hasColumn('it_tags', 'color', $c));
});

test('create, find, update and delete a model with timestamps and change tracking', function () {
    db();

    $author = ItAuthor::create(['name' => '  Ann  ', 'email' => 'ann@x.io', 'settings' => ['theme' => 'dark']]);

    assert_true($author->exists);
    assert_true($author->wasRecentlyCreated);
    assert_same(1, $author->id);
    assert_same('Ann', $author->name, 'mutator trimmed');
    assert_true($author->created_at instanceof DateTimeValue);
    assert_false($author->isDirty());

    $found = ItAuthor::find($author->id);
    assert_same('Ann', $found->name);
    assert_same(['theme' => 'dark'], $found->settings);
    assert_true($found->active);
    assert_true($found->is($author));

    $found->name = 'Anna';
    assert_true($found->isDirty('name'));
    assert_same('Ann', $found->getOriginal('name'));
    assert_true($found->save());
    assert_same(['name' => 'Anna'], array_intersect_key($found->getChanges(), ['name' => 1]));
    assert_true($found->wasChanged('name'));
    assert_same('Anna', ItAuthor::find($author->id)->name);

    assert_true($found->update(['settings' => ['theme' => 'light']]));
    assert_same('light', $found->fresh()->settings['theme']);

    assert_true($found->delete());
    assert_false($found->exists);
    assert_null(ItAuthor::find($author->id));
    assert_throws(fn () => ItAuthor::findOrFail($author->id), HttpException::class);
});

test('casts, accessors, appends and hidden attributes in arrays and JSON', function () {
    db();

    $author = ItAuthor::create(['name' => 'Bob', 'email' => 'bob@x.io', 'active' => false, 'joined_at' => '2026-01-15 13:45:00']);
    $author = $author->fresh();

    assert_false($author->active);
    assert_same('2026-01-15', $author->joined_at->toDateString());
    assert_same('2026-01-15 00:00:00', (string) $author->joined_at);

    $array = $author->toArray();
    assert_same('BOB', $array['upper_name']);
    assert_false(array_key_exists('email', $array));
    assert_same('2026-01-15 00:00:00', $array['joined_at']);
    assert_true(is_string($array['created_at']));
    assert_contains('"upper_name":"BOB"', json_encode($author));
    assert_same('bob@x.io', $author->email, 'hidden attributes stay readable');
    assert_same('bob@x.io', $author['email']);

    $author->settings = collect(['a' => 1]);
    $author->save();
    assert_same(['a' => 1], $author->fresh()->settings);
});

test('model events can observe and cancel operations', function () {
    db();

    $log = [];
    ItAuthor::creating(function (ItAuthor $a) use (&$log) {
        $log[] = 'creating';

        if ($a->name === 'blocked') {
            return false;
        }
    });
    ItAuthor::created(function () use (&$log) {
        $log[] = 'created';
    });
    ItAuthor::updated(function () use (&$log) {
        $log[] = 'updated';
    });
    ItAuthor::deleted(function () use (&$log) {
        $log[] = 'deleted';
    });

    $blocked = new ItAuthor(['name' => 'blocked', 'email' => 'b@x.io']);
    assert_false($blocked->save());
    assert_false($blocked->exists);
    assert_same(0, ItAuthor::count());

    $ok = ItAuthor::create(['name' => 'Ok', 'email' => 'ok@x.io']);
    $ok->update(['name' => 'Okay']);
    $ok->update(['name' => 'Okay']);
    $ok->delete();
    assert_same(['creating', 'creating', 'created', 'updated', 'deleted'], $log, 'no updated event without changes');

    $observer = new ItObserver();
    ItTag::observe($observer);
    $tag = ItTag::create(['name' => 'php']);
    assert_same(['creating:php', 'created:' . $tag->id], $observer->log);

    Model::withoutEvents(fn () => ItTag::create(['name' => 'quiet']));
    assert_count(2, $observer->log);
});

test('local scopes, global scopes and soft deletes', function () {
    db();

    $a = ItAuthor::create(['name' => 'A', 'email' => 'a@x.io', 'active' => true]);
    ItAuthor::create(['name' => 'B', 'email' => 'b@x.io', 'active' => false]);
    assert_same(['A'], ItAuthor::active()->pluck('name')->all());

    $post = ItPost::create(['title' => 'One', 'it_author_id' => $a->id, 'published' => true]);
    $draft = ItPost::create(['title' => 'Two', 'it_author_id' => $a->id]);
    assert_same(['One'], ItPost::published()->pluck('title')->all());

    assert_true($post->delete());
    assert_true($post->exists, 'soft-deleted models still exist');
    assert_true($post->trashed());
    assert_same(1, ItPost::count());
    assert_same(2, ItPost::withTrashed()->count());
    assert_same(['One'], ItPost::onlyTrashed()->pluck('title')->all());
    assert_null(ItPost::find($post->id));
    assert_not_null(ItPost::withTrashed()->find($post->id));

    assert_true($post->restore());
    assert_false($post->trashed());
    assert_same(2, ItPost::count());

    ItPost::where('id', $draft->id)->delete();
    assert_not_null(ItPost::withTrashed()->find($draft->id)->deleted_at, 'builder delete soft-deletes too');

    assert_true($draft->fresh()->forceDelete());
    assert_null(ItPost::withTrashed()->find($draft->id));
    assert_same(1, ItPost::withoutGlobalScopes()->count());
});

test('hasMany, hasOne and belongsTo relations load lazily and can create children', function () {
    db();

    $author = ItAuthor::create(['name' => 'A', 'email' => 'a@x.io']);
    $post = $author->posts()->create(['title' => 'First']);
    $author->posts()->createMany([['title' => 'Second'], ['title' => 'Third']]);
    $author->profile()->create(['bio' => 'Hello']);

    assert_same($author->id, $post->it_author_id);
    assert_same(3, $author->posts->count());
    assert_true($author->posts instanceof Collection);
    assert_same('Hello', $author->profile->bio);
    assert_same('A', ItPost::find($post->id)->author->name);
    assert_null(ItAuthor::create(['name' => 'Lonely', 'email' => 'l@x.io'])->profile);

    $comment = new ItComment(['body' => 'Nice']);
    $comment->post()->associate($post)->save();
    assert_same($post->id, $comment->post_id);
    assert_same('Nice', $post->comments()->first()->body);
    assert_same(2, $post->comments()->where('body', 'like', 'N%')->count() + 1);

    $author->load('posts');
    assert_true($author->relationLoaded('posts'));
    assert_same(3, $author->getRelation('posts')->count());
});

test('belongsToMany with pivot data, attach, detach, sync and toggle', function () {
    db();

    $author = ItAuthor::create(['name' => 'A', 'email' => 'a@x.io']);
    $post = $author->posts()->create(['title' => 'Tagged']);
    [$php, $sql, $js] = [ItTag::create(['name' => 'php']), ItTag::create(['name' => 'sql']), ItTag::create(['name' => 'js'])];

    $post->tags()->attach([$php->id => ['note' => 'main'], $sql->id]);
    $tags = $post->tags()->orderBy('it_tags.name')->get();

    assert_same(['php', 'sql'], $tags->pluck('name')->all());
    assert_same('main', $tags[0]->pivot->note);
    assert_null($tags[1]->pivot->note);
    assert_not_null($tags[0]->pivot->created_at);
    assert_same($post->id, $tags[0]->pivot->post_id);
    assert_same(['Tagged'], $php->posts->pluck('title')->all());

    $changes = $post->tags()->sync([$sql->id => ['note' => 'updated'], $js->id]);
    assert_same([$js->id], $changes['attached']);
    assert_same([$php->id], $changes['detached']);
    assert_same([$sql->id], $changes['updated']);
    assert_same(['js', 'sql'], $post->tags()->orderBy('it_tags.name')->pluck('it_tags.name')->all());
    assert_same('updated', $post->tags()->where('it_tags.id', $sql->id)->first()->pivot->note);

    $post->tags()->toggle([$php->id, $js->id]);
    assert_same(['php', 'sql'], $post->tags()->orderBy('it_tags.name')->pluck('it_tags.name')->all());

    assert_same(2, $post->tags()->detach());
    assert_same(0, $post->tags()->count());
});

test('eager loading avoids N+1 queries, supports nesting and constraints', function () {
    db();

    foreach (['A', 'B', 'C'] as $name) {
        $author = ItAuthor::create(['name' => $name, 'email' => strtolower($name) . '@x.io']);
        $post = $author->posts()->create(['title' => "$name post", 'published' => $name !== 'C']);
        $post->comments()->create(['body' => "comment on $name"]);
        $author->posts()->create(['title' => "$name draft"]);
    }

    $GLOBALS['__it_queries'] = 0;
    $authors = ItAuthor::with('posts.comments')->orderBy('name')->get();
    assert_same(3, db_queries(), 'authors + posts + comments');

    assert_same(2, $authors[0]->posts->count());
    assert_true($authors[0]->relationLoaded('posts'));
    assert_true($authors[0]->posts[0]->relationLoaded('comments'));
    assert_same(1, $authors->sum(fn ($a) => $a->posts->sum(fn ($p) => $p->comments->count())) === 3 ? 1 : 0);

    $constrained = ItAuthor::with(['posts' => fn ($q) => $q->where('published', 1)])->orderBy('name')->get();
    assert_same([1, 1, 0], $constrained->map(fn ($a) => $a->posts->count())->all());

    $posts = ItPost::with('author')->get();
    assert_true($posts->every(fn ($p) => $p->relationLoaded('author')));
    assert_same('A', $posts->first()->author->name);

    $GLOBALS['__it_queries'] = 0;
    $plain = ItPost::orderBy('id')->get();
    $plain->load('author');
    assert_same(2, db_queries());
    assert_true($plain[0]->relationLoaded('author'));

    $one = ItAuthor::first();
    $one->loadMissing('posts');
    assert_true($one->relationLoaded('posts'));
});

test('whereHas, has, doesntHave, withCount and loadCount', function () {
    db();

    $a = ItAuthor::create(['name' => 'A', 'email' => 'a@x.io']);
    $b = ItAuthor::create(['name' => 'B', 'email' => 'b@x.io']);
    ItAuthor::create(['name' => 'C', 'email' => 'c@x.io']);
    $a->posts()->createMany([['title' => 'a1', 'published' => true], ['title' => 'a2']]);
    $b->posts()->create(['title' => 'b1']);
    $a->posts()->first()->tags()->attach(ItTag::create(['name' => 't'])->id);

    assert_same(['A', 'B'], ItAuthor::has('posts')->orderBy('name')->pluck('name')->all());
    assert_same(['A'], ItAuthor::has('posts', '>=', 2)->pluck('name')->all());
    assert_same(['C'], ItAuthor::doesntHave('posts')->pluck('name')->all());
    assert_same(['A'], ItAuthor::whereHas('posts', fn ($q) => $q->where('published', 1))->pluck('name')->all());
    assert_same(['B', 'C'], ItAuthor::whereDoesntHave('posts', fn ($q) => $q->where('published', 1))->orderBy('name')->pluck('name')->all());
    assert_same(['A'], ItAuthor::has('posts.tags')->pluck('name')->all());
    assert_same(['a1'], ItPost::whereHas('author', fn ($q) => $q->where('name', 'A'))->where('published', 1)->pluck('title')->all());

    $counted = ItAuthor::withCount('posts', ['posts as published_count' => fn ($q) => $q->where('published', 1)])->orderBy('name')->get();
    assert_same([2, 1, 0], $counted->pluck('posts_count')->map(fn ($v) => (int) $v)->all());
    assert_same([1, 0, 0], $counted->pluck('published_count')->map(fn ($v) => (int) $v)->all());
    assert_same('A', $counted[0]->name, 'other columns still selected');

    $a->loadCount('posts');
    assert_same(2, (int) $a->posts_count);

    $a->posts()->first()->delete();
    assert_same(1, (int) ItAuthor::withCount('posts')->find($a->id)->posts_count, 'soft-deleted rows are not counted');
});

test('query builder execution: aggregates, pluck, chunk, cursor, upsert and friends', function () {
    db();

    $author = ItAuthor::create(['name' => 'A', 'email' => 'a@x.io']);

    foreach (range(1, 25) as $i) {
        ItPost::create(['title' => "Post $i", 'it_author_id' => $author->id, 'views' => $i, 'published' => $i % 2 === 0]);
    }

    $q = fn () => Database::table('it_posts');

    assert_same(25, $q()->count());
    assert_same(325, (int) $q()->sum('views'));
    assert_same(25, (int) $q()->max('views'));
    assert_same(13.0, round((float) $q()->avg('views'), 1));
    assert_same(12, ItPost::published()->count());
    assert_same('Post 25', $q()->where('views', 25)->value('title'));
    assert_same(['Post 1', 'Post 2'], $q()->orderBy('views')->limit(2)->pluck('title')->all());
    assert_same([1 => 'Post 1'], $q()->orderBy('views')->limit(1)->pluck('title', 'views')->all());
    assert_true($q()->where('views', '>', 20)->exists());
    assert_true($q()->where('views', '>', 200)->doesntExist());
    assert_same('Post 7', $q()->where('views', 7)->sole()['title']);
    assert_throws(fn () => $q()->where('views', '>', 0)->sole(), RuntimeException::class, 'Multiple');

    $pages = [];
    ItPost::orderBy('views')->chunk(10, function (Collection $posts, int $page) use (&$pages) {
        $pages[] = [$page, $posts->count(), $posts->first()->views];
    });
    assert_same([[1, 10, 1], [2, 10, 11], [3, 5, 21]], $pages);

    $seen = 0;
    ItPost::chunkById(7, function (Collection $posts) use (&$seen) {
        $seen += $posts->count();
    });
    assert_same(25, $seen);

    $streamed = 0;
    foreach (ItPost::where('views', '<=', 5)->cursor() as $post) {
        assert_true($post instanceof ItPost);
        $streamed++;
    }
    assert_same(5, $streamed);

    $stopped = ItPost::orderBy('id')->each(function (ItPost $post) {
        return $post->views < 3;
    }, 2);
    assert_false($stopped);

    $grouped = $q()->selectRaw('published, COUNT(*) AS n')->groupBy('published')->orderBy('published')->get();
    assert_same([13, 12], $grouped->pluck('n')->map(fn ($v) => (int) $v)->all());
    assert_same(2, $q()->selectRaw('published, COUNT(*) AS n')->groupBy('published')->count(), 'count over grouped query');

    $ids = $q()->whereIn('id', fn ($s) => $s->from('it_posts')->select('id')->where('views', '>', 23))->pluck('views')->all();
    assert_same([24, 25], array_map('intval', $ids));

    $union = $q()->select('title')->where('views', 1)->unionAll($q()->select('title')->where('views', 2))->get();
    assert_same(2, $union->count());

    assert_same(1, $q()->where('views', 1)->increment('views', 10));
    assert_same(11, (int) $q()->where('title', 'Post 1')->value('views'));
    ItPost::where('title', 'Post 1')->first()->decrement('views', 1);
    assert_same(10, (int) $q()->where('title', 'Post 1')->value('views'));

    Database::table('it_tags')->upsert([['name' => 'x'], ['name' => 'y']], 'name');
    Database::table('it_tags')->upsert([['name' => 'x'], ['name' => 'z']], 'name');
    assert_same(['x', 'y', 'z'], Database::table('it_tags')->orderBy('name')->pluck('name')->all());
    Database::table('it_tags')->insertOrIgnore([['name' => 'x'], ['name' => 'w']]);
    assert_same(4, Database::table('it_tags')->count());
    Database::table('it_tags')->updateOrInsert(['name' => 'w'], ['name' => 'ww']);
    Database::table('it_tags')->updateOrInsert(['name' => 'v'], []);
    assert_same(['v', 'ww', 'x', 'y', 'z'], Database::table('it_tags')->orderBy('name')->pluck('name')->all());

    assert_same(25, $q()->whereDate('created_at', date('Y-m-d'))->count());
    assert_same(25, $q()->whereYear('created_at', (int) date('Y'))->count());

    Database::table('it_tags')->truncate();
    assert_same(0, Database::table('it_tags')->count());
});

test('pagination over models', function () {
    db();

    $author = ItAuthor::create(['name' => 'A', 'email' => 'a@x.io']);

    foreach (range(1, 12) as $i) {
        $author->posts()->create(['title' => "P$i", 'views' => $i]);
    }

    $_GET = ['page' => '2', 'q' => 'x'];
    $_SERVER['REQUEST_URI'] = '/posts?page=2&q=x';

    $page = ItPost::orderBy('views')->paginate(5);
    assert_same(12, $page->total());
    assert_same(2, $page->currentPage());
    assert_same(3, $page->lastPage());
    assert_same(['P6', 'P7', 'P8', 'P9', 'P10'], $page->pluck('title')->all());
    assert_true($page[0] instanceof ItPost);
    assert_same('/posts?q=x&page=3', $page->nextPageUrl());
    assert_contains('page-item active', $page->links());
    assert_same(5, count($page->toArray()['data']));

    $simple = ItPost::orderBy('views')->simplePaginate(5, 3);
    assert_same(2, $simple->count());
    assert_false($simple->hasMorePages());
    assert_null($simple->total());
});

test('transactions commit, roll back and nest with savepoints', function () {
    $c = db();
    $connection = Database::connection($c);

    $result = Database::transaction(function () {
        ItTag::create(['name' => 'outer']);

        return 'done';
    });
    assert_same('done', $result);
    assert_same(1, ItTag::count());

    assert_throws(function () {
        Database::transaction(function () {
            ItTag::create(['name' => 'rolled']);

            throw new RuntimeException('boom');
        });
    }, RuntimeException::class, 'boom');
    assert_same(1, ItTag::count());
    assert_same(0, $connection->transactionLevel());

    Database::transaction(function () use ($connection) {
        ItTag::create(['name' => 'level1']);
        assert_same(1, $connection->transactionLevel());

        try {
            Database::transaction(function () use ($connection) {
                ItTag::create(['name' => 'level2']);
                assert_same(2, $connection->transactionLevel());

                throw new RuntimeException('inner');
            });
        } catch (RuntimeException) {
            // inner savepoint rolled back, outer continues
        }

        assert_same(1, $connection->transactionLevel());
    });

    assert_same(['level1', 'outer'], ItTag::orderBy('name')->pluck('name')->all());

    $connection->beginTransaction();
    ItTag::create(['name' => 'manual']);
    $connection->rollBack();
    assert_same(2, ItTag::count());
});

test('factories build and persist models, with states, sequences and nested factories', function () {
    db();

    $authors = ItAuthor::factory()->count(3)->create();
    assert_same(3, $authors->count());
    assert_same(3, ItAuthor::count());
    assert_true($authors->every(fn ($a) => $a->exists && $a->active));
    assert_same(3, $authors->pluck('email')->unique()->count());

    $inactive = ItAuthor::factory()->state(['active' => false])->make();
    assert_false($inactive->exists);
    assert_false($inactive->active);

    $seq = ItAuthor::factory()->count(4)->sequence(['name' => 'odd'], ['name' => 'even'])->make();
    assert_same(['odd', 'even', 'odd', 'even'], $seq->pluck('name')->all());

    $post = ItPost::factory()->create(['title' => 'Nested']);
    assert_true($post->author instanceof ItAuthor, 'nested factory created the author');
    assert_same(4, ItAuthor::count());

    $raw = ItPost::factory()->raw(['title' => 'Raw']);
    assert_same('Raw', $raw['title']);
    assert_true(is_int($raw['it_author_id']));

    $with = ItAuthor::factory()->afterCreating(fn (ItAuthor $a) => $a->posts()->create(['title' => 'hook']))->create();
    assert_same(1, $with->posts()->count());
});

test('firstOrCreate, updateOrCreate, destroy, replicate and refresh', function () {
    db();

    $a = ItAuthor::firstOrCreate(['email' => 'a@x.io'], ['name' => 'A']);
    $again = ItAuthor::firstOrCreate(['email' => 'a@x.io'], ['name' => 'Different']);
    assert_true($a->is($again));
    assert_same('A', $again->name);
    assert_same(1, ItAuthor::count());

    $updated = ItAuthor::updateOrCreate(['email' => 'a@x.io'], ['name' => 'A2']);
    assert_same('A2', $updated->name);
    assert_same('A2', ItAuthor::find($a->id)->name);
    ItAuthor::updateOrCreate(['email' => 'b@x.io'], ['name' => 'B']);
    assert_same(2, ItAuthor::count());

    $new = ItAuthor::firstOrNew(['email' => 'c@x.io'], ['name' => 'C']);
    assert_false($new->exists);
    assert_same('C', $new->name);

    $copy = $updated->replicate();
    assert_false($copy->exists);
    assert_null($copy->id);
    $copy->email = 'copy@x.io';
    $copy->save();
    assert_same(3, ItAuthor::count());

    Database::table('it_authors')->where('id', $a->id)->update(['name' => 'Changed outside']);
    assert_same('A', $a->name, 'in-memory instance is untouched until refreshed');
    $a->refresh();
    assert_same('Changed outside', $a->name);

    assert_same(2, ItAuthor::destroy([$a->id, $copy->id]));
    assert_same(1, ItAuthor::count());
    assert_same(['B'], ItAuthor::pluck('name')->all());
});

test('PHP migrations run, roll back and report status on the integration connection', function () {
    $c = db();

    if (Database::connection($c)->driver() !== 'sqlite') {
        skip('only runs on an isolated SQLite database (it drops the migrations table)');
    }

    $dir = sys_get_temp_dir() . '/it-migrations-' . bin2hex(random_bytes(3));
    mkdir($dir);

    file_put_contents($dir . '/2026_01_01_000000_create_it_migrated_table.php', <<<'PHP'
        <?php

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('it_migrated', function (Blueprint $table) {
                    $table->id();
                    $table->string('name');
                    $table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('it_migrated');
            }
        };
        PHP);

    file_put_contents($dir . '/2026_01_02_000000_add_note_to_it_migrated_table.sql', "-- up\nALTER TABLE it_migrated ADD COLUMN note VARCHAR(50) NULL;\n-- down\nALTER TABLE it_migrated DROP COLUMN note;\n");

    try {
        Schema::dropIfExists('it_migrated', $c);
        Schema::dropIfExists('migrations', $c);

        $migrator = new Migrator($dir, $c);

        assert_count(2, $migrator->pending());
        assert_same(['2026_01_01_000000_create_it_migrated_table', '2026_01_02_000000_add_note_to_it_migrated_table'], $migrator->run());
        assert_true(Schema::hasTable('it_migrated', $c));
        assert_true(Schema::hasColumn('it_migrated', 'note', $c));
        assert_same([], $migrator->run());

        $status = $migrator->status();
        assert_same(1, $status[0]['batch']);
        assert_same(1, $status[1]['batch']);

        assert_same(['2026_01_02_000000_add_note_to_it_migrated_table', '2026_01_01_000000_create_it_migrated_table'], $migrator->rollback());
        assert_false(Schema::hasTable('it_migrated', $c));
        assert_null($migrator->status()[0]['batch']);

        $migrator->run();
        assert_count(2, $migrator->reset());
        assert_false(Schema::hasTable('it_migrated', $c));
    } finally {
        Schema::dropIfExists('it_migrated', $c);
        Schema::dropIfExists('migrations', $c);

        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($dir);
    }
});

test('query log, listeners and QueryException carry the SQL', function () {
    $c = db();
    $connection = Database::connection($c);
    $connection->enableQueryLog();
    $connection->flushQueryLog();

    ItTag::create(['name' => 'logged']);
    $log = $connection->queryLog();
    assert_true(count($log) >= 1);
    assert_contains('INSERT INTO `it_tags`', end($log)['sql']);

    $e = assert_throws(fn () => Database::table('it_nope')->get(), QueryException::class);
    assert_same('SELECT * FROM `it_nope`', $e->sql);
    assert_contains('it_nope', $e->getMessage());
});

test('cleanup drops the integration tables', function () {
    $c = db();

    foreach (IT_TABLES as $table) {
        Schema::dropIfExists($table, $c);
    }

    assert_false(Schema::hasTable('it_authors', $c));
});
