<?php

// make:crud (console/tooling.php). Files are generated into a temporary
// directory, never into the project, and removed when the process ends.
// The last test loads the generated classes, migrates the generated table
// into the in-memory database and drives the resource over HTTP.

if (!function_exists('crud_scaffold')) {
    if (!function_exists('command')) {
        function command(string $name, string $description, callable $handler): void
        {
        }
    }

    require_once BASE_PATH . '/console/tooling.php';
}

function crud_test_root(): string
{
    static $root = null;

    if ($root === null) {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/comfree-crud-' . getmypid();
        mkdir($root, 0755, true);

        register_shutdown_function(function () use ($root): void {
            crud_test_remove($root);
        });
    }

    return $root;
}

function crud_test_remove(string $path): void
{
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;
        is_dir($child) ? crud_test_remove($child) : unlink($child);
    }

    rmdir($path);
}

function crud_test_lint(string $file): void
{
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

    assert_same(0, $code, basename($file) . ': ' . implode(' ', $output));
}

test('field specs are parsed and names inflected', function () {
    $fields = crud_parse_fields('title:string, body:text,published:bool,price:decimal?,Count:int');

    assert_count(5, $fields);
    assert_same(['name' => 'title', 'type' => 'string', 'nullable' => false], $fields[0]);
    assert_same('boolean', $fields[2]['type']);
    assert_same(['name' => 'price', 'type' => 'float', 'nullable' => true], $fields[3]);
    assert_same('count', $fields[4]['name']);

    assert_throws(fn () => crud_parse_fields('title:blob'), InvalidArgumentException::class, 'Unknown field type');
    assert_throws(fn () => crud_parse_fields('id:integer'), InvalidArgumentException::class, 'Invalid field name');
    assert_throws(fn () => crud_parse_fields(''), InvalidArgumentException::class);

    $post = crud_names('post');
    assert_same('Post', $post['model']);
    assert_same('posts', $post['table']);
    assert_same('PostsController', $post['controller']);
    assert_same('PostPolicy', $post['policy']);

    assert_same('categories', crud_names('Category')['table']);
    assert_same('boxes', crud_names('Box')['table']);
    assert_same('dishes', crud_names('Dish')['table']);
    assert_same('blog_posts', crud_names('BlogPost')['table']);
    assert_same('blog-posts', crud_names('BlogPost')['uri']);
    assert_same('BlogPostsController', crud_names('BlogPost')['controller']);
    assert_same('blogPost', crud_names('BlogPost')['variable']);
    assert_same('Blog post', crud_names('BlogPost')['title']);

    assert_throws(fn () => crud_names(''), InvalidArgumentException::class);
});

test('every file is written to the target directory and never overwritten without --force', function () {
    $root = crud_test_root() . '/plain';
    $fields = crud_parse_fields('title:string,body:text,published:boolean');

    $result = crud_scaffold('Post', $fields, ['root' => $root]);

    assert_same([], $result['skipped']);
    assert_count(11, $result['created']);

    foreach (['models/Post.php', 'database/factories/PostFactory.php', 'policies/PostPolicy.php', 'controllers/PostsController.php',
        'views/posts/index.tpl', 'views/posts/show.tpl', 'views/posts/create.tpl', 'views/posts/edit.tpl', 'views/posts/_form.tpl', 'tests/PostsTest.php'] as $file) {
        assert_true(is_file($root . '/' . $file), "missing $file");
    }

    $migrations = glob($root . '/database/migrations/*_create_posts_table.php') ?: [];
    assert_count(1, $migrations);

    foreach (glob($root . '/{models,database/factories,database/migrations,policies,controllers,tests}/*.php', GLOB_BRACE) ?: [] as $file) {
        crud_test_lint($file);
    }

    $model = (string) file_get_contents($root . '/models/Post.php');
    assert_contains("\$fillable = ['title', 'body', 'published']", $model);
    assert_contains("'published' => 'bool'", $model);

    $migration = (string) file_get_contents($migrations[0]);
    assert_contains("\$table->string('title');", $migration);
    assert_contains("\$table->text('body');", $migration);
    assert_contains("\$table->boolean('published')->default(false);", $migration);
    assert_contains("Schema::dropIfExists('posts');", $migration);

    $controller = (string) file_get_contents($root . '/controllers/PostsController.php');
    assert_contains('public function show(Post $post)', $controller);
    assert_contains("authorize('update', \$post);", $controller);
    assert_contains("'title' => 'required|string|max:255'", $controller);
    assert_contains("'published' => 'nullable|boolean'", $controller);

    $form = (string) file_get_contents($root . '/views/posts/_form.tpl');
    assert_contains('<textarea', $form);
    assert_contains('type="checkbox"', $form);
    assert_contains('{$old.title|default:$post.title|default:\'\'}', $form);
    assert_contains("{extends file='layouts/main.tpl'}", (string) file_get_contents($root . '/views/posts/index.tpl'));

    assert_contains("get('/posts', 'PostsController@index', 'posts.index');", $result['routes']);
    assert_contains("delete('/posts/{id:\\d+}', 'PostsController@destroy', 'posts.destroy', ['auth']);", $result['routes']);

    // Second run: nothing is touched.
    $again = crud_scaffold('Post', $fields, ['root' => $root]);
    assert_same([], $again['created']);
    assert_count(11, $again['skipped']);
    assert_count(1, glob($root . '/database/migrations/*_create_posts_table.php') ?: []);

    // --force rewrites in place, reusing the existing migration file.
    $forced = crud_scaffold('Post', crud_parse_fields('title:string'), ['root' => $root, 'force' => true]);
    assert_count(11, $forced['created']);
    assert_count(1, glob($root . '/database/migrations/*_create_posts_table.php') ?: []);
    assert_not_contains("text('body')", (string) file_get_contents($migrations[0]));

    // --routes appends a commented block once.
    mkdir($root . '/routes');
    file_put_contents($root . '/routes/web.php', "<?php\n\nget('/', fn () => 'home', 'home');\n");
    crud_scaffold('Post', $fields, ['root' => $root, 'force' => true, 'routes' => true]);
    crud_scaffold('Post', $fields, ['root' => $root, 'force' => true, 'routes' => true]);

    $routes = (string) file_get_contents($root . '/routes/web.php');
    assert_same(1, substr_count($routes, '// make:crud Post'));
    assert_same(1, substr_count($routes, "'PostsController@index'"));
});

test('the generated resource migrates, renders and handles the full CRUD flow', function () {
    $root = crud_test_root() . '/live';
    $result = crud_scaffold('Gizmo', crud_parse_fields('title:string,body:text,published:boolean,price:float?'), ['root' => $root]);
    assert_count(11, $result['created']);

    use_test_database();

    foreach (['models/Gizmo.php', 'database/factories/GizmoFactory.php', 'policies/GizmoPolicy.php', 'controllers/GizmosController.php'] as $file) {
        require_once $root . '/' . $file;
    }

    if (!Schema::hasTable('gizmos')) {
        (require glob($root . '/database/migrations/*_create_gizmos_table.php')[0])->up();
    }

    Database::table('gizmos')->delete();

    // Templates come from the temporary directory, the layout from the project.
    config_set('view.paths', [$root . '/views', BASE_PATH . '/views']);
    View::reset();

    http_use_app_routes();
    eval($result['routes']);

    try {
        $gizmo = Gizmo::factory()->create(['title' => 'Factory gizmo']);
        assert_true(is_bool($gizmo->published));

        http_get('/gizmos')->assertOk()->assertSee('Factory gizmo')->assertSee('1 in total');
        http_get('/gizmos/' . $gizmo->id)->assertOk()->assertSee('Gizmo #' . $gizmo->id)->assertSee('Factory gizmo');
        http_get('/gizmos/999')->assertNotFound();
        http_get('/gizmos/create')->assertRedirect('/login');
        http_post('/gizmos', ['title' => 'x'])->assertRedirect('/login');

        $user = User::factory()->create();
        acting_as($user);

        http_get('/gizmos/create')->assertOk()->assertSee('name="title"')->assertSee('type="checkbox"');
        http_post('/gizmos', ['title' => '', 'body' => ''])->assertRedirect('/gizmos/create')->assertSessionHasErrors(['title', 'body'])->assertValid('price');

        http_post('/gizmos', ['title' => 'Made in test', 'body' => 'Body text', 'published' => '1', 'price' => '9.5'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/gizmos/' . ($gizmo->id + 1));

        assert_database_has('gizmos', ['title' => 'Made in test', 'published' => 1, 'price' => 9.5]);
        $made = Gizmo::where('title', 'Made in test')->first();
        assert_same(9.5, $made->price);
        assert_true($made->published);

        http_get('/gizmos/' . $made->id)->assertOk()->assertSee('Made in test')->assertSee('<dd class="col-sm-9">Yes</dd>');
        http_get('/gizmos/' . $made->id . '/edit')->assertOk()->assertSee('value="Made in test"')->assertSee('checked');

        http_put('/gizmos/' . $made->id, ['title' => 'Renamed', 'body' => 'Body text'])->assertRedirect('/gizmos/' . $made->id);
        assert_database_has('gizmos', ['id' => $made->id, 'title' => 'Renamed', 'published' => 0]);

        http_delete('/gizmos/' . $made->id)->assertRedirect('/gizmos');
        assert_database_missing('gizmos', ['id' => $made->id]);
        assert_database_count('gizmos', 1);

        http_get('/gizmos')->assertOk()->assertSee('deleted');
    } finally {
        // The in-memory database is shared with the other test files.
        Database::table('gizmos')->delete();

        if (isset($user)) {
            Database::table('users')->where('id', $user->id)->delete();
        }

        View::reset();
    }
});
