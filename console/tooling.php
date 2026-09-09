<?php

// Developer tooling commands: make:crud. Loaded by console.php after the
// built-in commands, so command(), parse_arguments(), line() and friends
// exist. crud_scaffold() is a plain function so tests can point it at a
// temporary directory instead of the project.

// Column type → migration call, validation rule, model cast, form control,
// factory value and a sample value for the generated test.
const CRUD_TYPES = [
    'string' => ['column' => "string('%s')", 'rule' => 'string|max:255', 'cast' => null, 'input' => 'text', 'fake' => 'fake()->sentence(3)', 'sample' => "'Sample %s'"],
    'text' => ['column' => "text('%s')", 'rule' => 'string', 'cast' => null, 'input' => 'textarea', 'fake' => 'fake()->paragraph()', 'sample' => "'Sample %s text'"],
    'integer' => ['column' => "integer('%s')", 'rule' => 'integer', 'cast' => 'int', 'input' => 'number', 'fake' => 'fake()->number(1, 100)', 'sample' => '7'],
    'boolean' => ['column' => "boolean('%s')->default(false)", 'rule' => 'boolean', 'cast' => 'bool', 'input' => 'checkbox', 'fake' => 'fake()->boolean()', 'sample' => "'1'"],
    'float' => ['column' => "decimal('%s', 10, 2)", 'rule' => 'numeric', 'cast' => 'float', 'input' => 'decimal', 'fake' => 'fake()->float(1, 1000)', 'sample' => "'9.5'"],
    'date' => ['column' => "date('%s')", 'rule' => 'date', 'cast' => 'date', 'input' => 'date', 'fake' => 'fake()->date()', 'sample' => "'2026-01-15'"],
    'datetime' => ['column' => "dateTime('%s')", 'rule' => 'date', 'cast' => 'datetime', 'input' => 'text', 'fake' => 'fake()->dateTime()', 'sample' => "'2026-01-15 10:30:00'"],
];

const CRUD_TYPE_ALIASES = ['int' => 'integer', 'bool' => 'boolean', 'decimal' => 'float', 'double' => 'float', 'str' => 'string', 'longtext' => 'text'];

// "title:string,body:text,published:boolean?" → [['name' => 'title', 'type' => 'string', 'nullable' => false], ...]
function crud_parse_fields(string $spec): array
{
    $fields = [];

    foreach (array_filter(array_map('trim', explode(',', $spec))) as $item) {
        [$name, $type] = array_pad(explode(':', $item, 2), 2, 'string');
        $name = str_snake(trim($name));
        $type = strtolower(trim($type));
        $nullable = str_ends_with($type, '?');
        $type = rtrim($type, '?');
        $type = CRUD_TYPE_ALIASES[$type] ?? $type;

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name) || in_array($name, ['id', 'created_at', 'updated_at'], true)) {
            throw new InvalidArgumentException("Invalid field name [$name].");
        }

        if (!isset(CRUD_TYPES[$type])) {
            throw new InvalidArgumentException("Unknown field type [$type] for [$name]. Use: " . implode(', ', array_keys(CRUD_TYPES)) . '.');
        }

        $fields[] = ['name' => $name, 'type' => $type, 'nullable' => $nullable];
    }

    if ($fields === []) {
        throw new InvalidArgumentException('At least one field is required (--fields=title:string,body:text).');
    }

    return $fields;
}

// Names derived from the model name: Post → posts table, PostsController,
// posts/ views, posts.* routes, /posts URLs.
function crud_names(string $name): array
{
    $model = str_studly(trim($name));

    if ($model === '' || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $model)) {
        throw new InvalidArgumentException('Usage: php console.php make:crud Post --fields=title:string,body:text');
    }

    $plural = str_plural($model);
    $table = str_snake($plural);

    return [
        'model' => $model,
        'plural' => $plural,
        'table' => $table,
        'controller' => $plural . 'Controller',
        'policy' => $model . 'Policy',
        'factory' => $model . 'Factory',
        'variable' => str_camel($model),
        'collection' => str_camel($plural),
        'route' => $table,                                  // posts.index
        'uri' => str_replace('_', '-', $table),             // /blog-posts
        'title' => ucfirst(str_replace('_', ' ', str_snake($model))),
        'title_plural' => ucfirst(str_replace('_', ' ', $table)),
    ];
}

// Writes every file of the resource under $root (BASE_PATH by default).
// Returns ['created' => [...], 'skipped' => [...], 'routes' => 'php code'].
function crud_scaffold(string $name, array $fields, array $options = []): array
{
    $root = rtrim(str_replace('\\', '/', (string) ($options['root'] ?? BASE_PATH)), '/');
    $force = (bool) ($options['force'] ?? false);
    $n = crud_names($name);
    $result = ['created' => [], 'skipped' => [], 'routes' => crud_routes_stub($n)];

    $write = function (string $relative, string $contents) use ($root, $force, &$result): void {
        $path = $root . '/' . $relative;

        if (is_file($path) && !$force) {
            $result['skipped'][] = $relative;

            return;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
        $result['created'][] = $relative;
    };

    // Reuse an existing create_<table>_table migration rather than adding a second one.
    $existing = glob($root . '/database/migrations/*_create_' . $n['table'] . '_table.php') ?: [];
    $migration = $existing !== []
        ? 'database/migrations/' . basename($existing[0])
        : 'database/migrations/' . date('Y_m_d_His') . '_create_' . $n['table'] . '_table.php';

    $write('models/' . $n['model'] . '.php', crud_model_stub($n, $fields));
    $write($migration, crud_migration_stub($n, $fields));
    $write('database/factories/' . $n['factory'] . '.php', crud_factory_stub($n, $fields));
    $write('policies/' . $n['policy'] . '.php', crud_policy_stub($n));
    $write('controllers/' . $n['controller'] . '.php', crud_controller_stub($n, $fields));
    $write('views/' . $n['table'] . '/index.tpl', crud_view_index_stub($n, $fields));
    $write('views/' . $n['table'] . '/show.tpl', crud_view_show_stub($n, $fields));
    $write('views/' . $n['table'] . '/create.tpl', crud_view_create_stub($n));
    $write('views/' . $n['table'] . '/edit.tpl', crud_view_edit_stub($n));
    $write('views/' . $n['table'] . '/_form.tpl', crud_view_form_stub($n, $fields));
    $write('tests/' . $n['plural'] . 'Test.php', crud_test_stub($n, $fields));

    if (!empty($options['routes'])) {
        $routesFile = $root . '/routes/web.php';
        $block = "\n// make:crud {$n['model']} — remove this comment once the routes are where you want them.\n" . $result['routes'];

        if (!is_file($routesFile) || !str_contains((string) file_get_contents($routesFile), "'{$n['controller']}@index'")) {
            file_put_contents($routesFile, $block, FILE_APPEND);
            $result['created'][] = 'routes/web.php (appended)';
        } else {
            $result['skipped'][] = 'routes/web.php (already has ' . $n['controller'] . ' routes)';
        }
    }

    return $result;
}

function crud_routes_stub(array $n): string
{
    $c = $n['controller'];
    $r = $n['route'];
    $u = '/' . $n['uri'];

    return <<<PHP
        get('$u', '$c@index', '$r.index');
        get('$u/create', '$c@create', '$r.create', ['auth']);
        post('$u', '$c@store', '$r.store', ['auth']);
        get('$u/{id:\\d+}', '$c@show', '$r.show');
        get('$u/{id:\\d+}/edit', '$c@edit', '$r.edit', ['auth']);
        put('$u/{id:\\d+}', '$c@update', '$r.update', ['auth']);
        delete('$u/{id:\\d+}', '$c@destroy', '$r.destroy', ['auth']);

        PHP;
}

function crud_model_stub(array $n, array $fields): string
{
    $fillable = implode(', ', array_map(fn ($f) => "'{$f['name']}'", $fields));
    $casts = [];

    foreach ($fields as $field) {
        $cast = CRUD_TYPES[$field['type']]['cast'];

        if ($cast !== null) {
            $casts[] = "'{$field['name']}' => '$cast'";
        }
    }

    $castsCode = $casts === [] ? '[]' : "[\n        " . implode(",\n        ", $casts) . ",\n    ]";

    return <<<PHP
        <?php

        class {$n['model']} extends Model
        {
            protected static string \$table = '{$n['table']}';

            // Columns that create()/fill()/update() may set from user input.
            protected static array \$fillable = [$fillable];

            // Attribute casts: 'int', 'bool', 'float', 'array', 'datetime', 'date', ...
            protected static array \$casts = $castsCode;
        }

        PHP;
}

function crud_migration_stub(array $n, array $fields): string
{
    $columns = '';

    foreach ($fields as $field) {
        $columns .= '            $table->' . sprintf(CRUD_TYPES[$field['type']]['column'], $field['name'])
            . ($field['nullable'] ? '->nullable()' : '') . ";\n";
    }

    return <<<PHP
        <?php

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$n['table']}', function (Blueprint \$table) {
                    \$table->id();
        {$columns}            \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$n['table']}');
            }
        };

        PHP;
}

function crud_factory_stub(array $n, array $fields): string
{
    $definition = '';

    foreach ($fields as $field) {
        $definition .= "            '{$field['name']}' => " . CRUD_TYPES[$field['type']]['fake'] . ",\n";
    }

    return <<<PHP
        <?php

        // {$n['model']}::factory()->count(5)->create();

        class {$n['factory']} extends Factory
        {
            protected string \$model = '{$n['model']}';

            public function definition(): array
            {
                return [
        {$definition}        ];
            }
        }

        PHP;
}

function crud_policy_stub(array $n): string
{
    $m = $n['model'];
    $v = '$' . $n['variable'];

    return <<<PHP
        <?php

        // Answers can('view', $v), authorize('update', $v), ... for the $m model.
        // Anyone may read; writing needs a logged-in user. Tighten the rules
        // (e.g. {$v}->user_id === \$user->id) once the table has an owner column.

        class {$n['policy']}
        {
            public function viewAny(?User \$user): bool
            {
                return true;
            }

            public function view(?User \$user, $m $v): bool
            {
                return true;
            }

            public function create(User \$user): bool
            {
                return true;
            }

            public function update(User \$user, $m $v): bool
            {
                return true;
            }

            public function delete(User \$user, $m $v): bool
            {
                return true;
            }
        }

        PHP;
}

function crud_controller_stub(array $n, array $fields): string
{
    $m = $n['model'];
    $v = '$' . $n['variable'];
    $dir = $n['table'];
    $r = $n['route'];
    $rules = '';
    $booleans = '';

    foreach ($fields as $field) {
        $type = CRUD_TYPES[$field['type']];

        if ($field['type'] === 'boolean') {
            // Unchecked boxes are absent from the form, so the key is filled in below.
            $rules .= "            '{$field['name']}' => 'nullable|boolean',\n";
            $booleans .= "        \$data['{$field['name']}'] = filter_var(input('{$field['name']}'), FILTER_VALIDATE_BOOLEAN);\n";
        } else {
            $rules .= "            '{$field['name']}' => '" . ($field['nullable'] ? 'nullable' : 'required') . '|' . $type['rule'] . "',\n";
        }
    }

    return <<<PHP
        <?php

        class {$n['controller']}
        {
            public function index()
            {
                authorize('viewAny', $m::class);

                return view('$dir/index', [
                    '{$n['collection']}' => $m::query()->orderByDesc('id')->paginate(15),
                ]);
            }

            public function show($m $v)
            {
                authorize('view', $v);

                return view('$dir/show', ['{$n['variable']}' => $v]);
            }

            public function create()
            {
                authorize('create', $m::class);

                return view('$dir/create');
            }

            public function store()
            {
                authorize('create', $m::class);

                $v = $m::create(\$this->validated());

                flash('success', '{$n['title']} created.');

                return redirect_route('$r.show', ['id' => {$v}->id]);
            }

            public function edit($m $v)
            {
                authorize('update', $v);

                return view('$dir/edit', ['{$n['variable']}' => $v]);
            }

            public function update($m $v)
            {
                authorize('update', $v);

                {$v}->update(\$this->validated());

                flash('success', '{$n['title']} updated.');

                return redirect_route('$r.show', ['id' => {$v}->id]);
            }

            public function destroy($m $v)
            {
                authorize('delete', $v);

                {$v}->delete();

                flash('success', '{$n['title']} deleted.');

                return redirect_route('$r.index');
            }

            // On failure validated() redirects back with the errors and old input.
            private function validated(): array
            {
                \$data = validated(input(), [
        {$rules}        ]);
        {$booleans}
                return \$data;
            }
        }

        PHP;
}

// First string/text column, used as the label in lists and tests.
function crud_label_field(array $fields): string
{
    foreach ($fields as $field) {
        if (in_array($field['type'], ['string', 'text'], true)) {
            return $field['name'];
        }
    }

    return $fields[0]['name'];
}

function crud_field_label(string $name): string
{
    return ucfirst(str_replace('_', ' ', $name));
}

// {$post.published} → Yes/No, others print as-is.
function crud_display(string $variable, array $field): string
{
    $expr = '$' . $variable . '.' . $field['name'];

    return $field['type'] === 'boolean' ? "{if $expr}Yes{else}No{/if}" : "{{$expr}}";
}

function crud_view_index_stub(array $n, array $fields): string
{
    $c = $n['collection'];
    $v = $n['variable'];
    $r = $n['route'];
    $columns = array_slice($fields, 0, 4);
    $colspan = count($columns) + 2;
    $lower = strtolower($n['title']);
    $headers = implode('', array_map(fn ($f) => '<th>' . crud_field_label($f['name']) . '</th>', $columns));
    $cells = implode('', array_map(fn ($f) => "\n                                <td>" . crud_display($v, $f) . '</td>', $columns));

    return <<<TPL
        {extends file='layouts/main.tpl'}

        {block name='title'}{$n['title_plural']} — {\$app_name}{/block}

        {block name='content'}

        <section class="hero pb-4">
            <div class="container">
                <h1>{$n['title_plural']}</h1>
                <p class="lead mx-auto">{\${$c}->total()} in total.</p>
                {if 'create'|can:'{$n['model']}'}
                    <a href="{navigate name='$r.create'}" class="btn btn-brand px-4 py-2 mt-2">New $lower</a>
                {/if}
            </div>
        </section>

        <section class="container mb-5">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr><th>#</th>$headers<th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        {foreach \${$c} as \$$v}
                            <tr>
                                <td>{\$$v.id}</td>$cells
                                <td class="text-end text-nowrap">
                                    <a href="{navigate name='$r.show' id=\$$v.id}" class="btn btn-sm btn-brand-outline">View</a>
                                    {if 'update'|can:\$$v}
                                        <a href="{navigate name='$r.edit' id=\$$v.id}" class="btn btn-sm btn-brand-outline">Edit</a>
                                    {/if}
                                </td>
                            </tr>
                        {foreachelse}
                            <tr><td colspan="$colspan" class="text-muted">Nothing here yet.</td></tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>

            {\${$c}->links()|raw}
        </section>

        {/block}

        TPL;
}

function crud_view_show_stub(array $n, array $fields): string
{
    $v = $n['variable'];
    $r = $n['route'];
    $rows = '';

    foreach ($fields as $field) {
        $rows .= "                        <dt class=\"col-sm-3\">" . crud_field_label($field['name']) . "</dt>\n"
            . "                        <dd class=\"col-sm-9\">" . crud_display($v, $field) . "</dd>\n";
    }

    return <<<TPL
        {extends file='layouts/main.tpl'}

        {block name='title'}{$n['title']} #{\$$v.id} — {\$app_name}{/block}

        {block name='content'}

        <section class="hero pb-4">
            <div class="container">
                <h1>{$n['title']} #{\$$v.id}</h1>
                <p class="lead mx-auto">Created {\$$v.created_at}</p>
            </div>
        </section>

        <section class="container mb-5">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="form-card">
                        <dl class="row mb-0">
        $rows                </dl>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <a href="{navigate name='$r.index'}" class="btn btn-brand-outline">Back to list</a>
                        {if 'update'|can:\$$v}
                            <a href="{navigate name='$r.edit' id=\$$v.id}" class="btn btn-brand">Edit</a>
                        {/if}
                        {if 'delete'|can:\$$v}
                            <form method="post" action="{navigate name='$r.destroy' id=\$$v.id}" class="ms-auto">
                                {csrf_field}
                                {method_field method='DELETE'}
                                <button type="submit" class="btn btn-outline-danger">Delete</button>
                            </form>
                        {/if}
                    </div>
                </div>
            </div>
        </section>

        {/block}

        TPL;
}

function crud_view_create_stub(array $n): string
{
    $r = $n['route'];
    $lower = strtolower($n['title']);

    return <<<TPL
        {extends file='layouts/main.tpl'}

        {block name='title'}New $lower — {\$app_name}{/block}

        {block name='content'}

        <section class="hero pb-4">
            <div class="container">
                <h1>New $lower</h1>
            </div>
        </section>

        <section class="container mb-5">
            <div class="row justify-content-center">
                <div class="col-md-7">
                    <div class="form-card">
                        <form method="post" action="{navigate name='$r.store'}" novalidate>
                            {csrf_field}
                            {include file='{$n['table']}/_form.tpl' {$n['variable']}=null}
                            <button type="submit" class="btn btn-brand px-4 py-2">Create</button>
                            <a href="{navigate name='$r.index'}" class="btn btn-link">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        {/block}

        TPL;
}

function crud_view_edit_stub(array $n): string
{
    $r = $n['route'];
    $v = $n['variable'];
    $lower = strtolower($n['title']);

    return <<<TPL
        {extends file='layouts/main.tpl'}

        {block name='title'}Edit $lower #{\$$v.id} — {\$app_name}{/block}

        {block name='content'}

        <section class="hero pb-4">
            <div class="container">
                <h1>Edit $lower #{\$$v.id}</h1>
            </div>
        </section>

        <section class="container mb-5">
            <div class="row justify-content-center">
                <div class="col-md-7">
                    <div class="form-card">
                        <form method="post" action="{navigate name='$r.update' id=\$$v.id}" novalidate>
                            {csrf_field}
                            {method_field method='PUT'}
                            {include file='{$n['table']}/_form.tpl' $v=\$$v}
                            <button type="submit" class="btn btn-brand px-4 py-2">Save changes</button>
                            <a href="{navigate name='$r.show' id=\$$v.id}" class="btn btn-link">Cancel</a>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        {/block}

        TPL;
}

// Shared form fields; $post is null on the create page and old input wins
// after a failed validation.
function crud_view_form_stub(array $n, array $fields): string
{
    $v = $n['variable'];
    $out = "{* Shared by create.tpl and edit.tpl. \$$v is null when creating. *}\n";

    foreach ($fields as $field) {
        $name = $field['name'];
        $label = crud_field_label($name);
        $input = CRUD_TYPES[$field['type']]['input'];
        $invalid = "{if isset(\$errors.$name)} is-invalid{/if}";
        $value = "{\$old.$name|default:\$$v.$name|default:''}";
        $feedback = "    {if isset(\$errors.$name)}\n        <div class=\"invalid-feedback\">{\$errors.$name.0}</div>\n    {/if}\n";

        if ($input === 'checkbox') {
            $out .= <<<TPL

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input$invalid" id="$name" name="$name" value="1"
                        {if \$old.$name|default:\$$v.$name|default:false}checked{/if}>
                    <label class="form-check-label" for="$name">$label</label>
                $feedback</div>

                TPL;

            continue;
        }

        $control = match ($input) {
            'textarea' => "<textarea class=\"form-control$invalid\" id=\"$name\" name=\"$name\" rows=\"5\">$value</textarea>",
            'number' => "<input type=\"number\" class=\"form-control$invalid\" id=\"$name\" name=\"$name\" value=\"$value\">",
            'decimal' => "<input type=\"number\" step=\"any\" class=\"form-control$invalid\" id=\"$name\" name=\"$name\" value=\"$value\">",
            'date' => "<input type=\"date\" class=\"form-control$invalid\" id=\"$name\" name=\"$name\" value=\"$value\">",
            default => "<input type=\"text\" class=\"form-control$invalid\" id=\"$name\" name=\"$name\" value=\"$value\">",
        };

        $out .= <<<TPL

            <div class="mb-3">
                <label for="$name" class="form-label">$label</label>
                $control
            $feedback</div>

            TPL;
    }

    return $out;
}

function crud_test_stub(array $n, array $fields): string
{
    $m = $n['model'];
    $u = '/' . $n['uri'];
    $label = crud_label_field($fields);
    $payload = '';
    $required = [];

    foreach ($fields as $field) {
        $sample = sprintf(CRUD_TYPES[$field['type']]['sample'], str_replace('_', ' ', $field['name']));
        $payload .= "        '{$field['name']}' => $sample,\n";

        if (!$field['nullable'] && $field['type'] !== 'boolean') {
            $required[] = "'{$field['name']}'";
        }
    }

    $requiredList = implode(', ', $required);
    $seeLabel = CRUD_TYPES[$fields[array_search($label, array_column($fields, 'name'), true)]['type']]['cast'] === null
        ? "->assertSee(\${$n['variable']}->$label)"
        : '';

    return <<<PHP
        <?php

        // Generated by make:crud. Expects the $m routes to be registered in
        // routes/web.php (make:crud prints them; --routes appends them).

        before_each(function () {
            use_test_database();
            http_use_app_routes();
            Database::beginTransaction();
        });

        after_each(function () {
            if (Database::transactionLevel() > 0) {
                Database::rollBack();
            }
        });

        function {$n['table']}_payload(array \$overrides = []): array
        {
            return \$overrides + [
        $payload    ];
        }

        test('{$n['table']} are listed and shown', function () {
            \${$n['variable']} = $m::factory()->create();

            http_get('$u')->assertOk()$seeLabel;
            http_get('$u/' . \${$n['variable']}->id)->assertOk()->assertSee('{$n['title']} #' . \${$n['variable']}->id);
            http_get('$u/999999')->assertNotFound();
        });

        test('guests cannot create {$n['table']}', function () {
            http_get('$u/create')->assertRedirect('/login');
            http_post('$u', {$n['table']}_payload())->assertRedirect('/login');
        });

        test('a user can create a {$n['variable']}', function () {
            acting_as(User::factory()->create());

            http_get('$u/create')->assertOk()->assertSee('name="_token"');
            \$response = http_post('$u', {$n['table']}_payload())->assertSessionHasNoErrors();

            \${$n['variable']} = $m::query()->orderByDesc('id')->first();
            assert_not_null(\${$n['variable']});
            \$response->assertRedirect('$u/' . \${$n['variable']}->id);
            assert_database_count('{$n['table']}', 1);
        });

        test('validation errors are flashed back', function () {
            acting_as(User::factory()->create());

            http_get('$u/create');
            http_post('$u', [])->assertRedirect('$u/create')->assertSessionHasErrors([$requiredList]);
            assert_database_count('{$n['table']}', 0);
        });

        test('a user can update and delete a {$n['variable']}', function () {
            acting_as(User::factory()->create());
            \${$n['variable']} = $m::factory()->create();

            http_get('$u/' . \${$n['variable']}->id . '/edit')->assertOk()->assertSee('name="_method"');
            http_put('$u/' . \${$n['variable']}->id, {$n['table']}_payload())->assertRedirect('$u/' . \${$n['variable']}->id);
            assert_database_has('{$n['table']}', ['id' => \${$n['variable']}->id, '$label' => {$n['table']}_payload()['$label']]);

            http_delete('$u/' . \${$n['variable']}->id)->assertRedirect('$u');
            assert_database_missing('{$n['table']}', ['id' => \${$n['variable']}->id]);
        });

        PHP;
}

command('make:crud', 'Scaffold a resource [Name] [--fields=title:string,body:text,published:boolean] [--force] [--routes]', function (array $args) {
    [$positional, $options] = parse_arguments($args);
    $name = trim((string) ($positional[0] ?? ''));

    if ($name === '') {
        error_line('Usage: php console.php make:crud Post --fields=title:string,body:text,published:boolean');
        error_line('Types: ' . implode(', ', array_keys(CRUD_TYPES)) . '; append ? for nullable (summary:text?).');

        return 1;
    }

    try {
        $fields = crud_parse_fields((string) ($options['fields'] ?? 'name:string'));
        $result = crud_scaffold($name, $fields, [
            'force' => isset($options['force']),
            'routes' => isset($options['routes']),
        ]);
    } catch (InvalidArgumentException $e) {
        error_line($e->getMessage());

        return 1;
    }

    foreach ($result['created'] as $file) {
        line('Created ' . $file);
    }

    foreach ($result['skipped'] as $file) {
        error_line('Already exists (use --force to overwrite): ' . $file);
    }

    if (!isset($options['routes'])) {
        line();
        line('Add these routes to routes/web.php (or re-run with --routes):');
        line();
        line(rtrim($result['routes']));
    }

    line();
    line('Then: php console.php migrate && php console.php test ' . strtolower(crud_names($name)['plural']));

    return $result['skipped'] === [] ? 0 : 1;
});
