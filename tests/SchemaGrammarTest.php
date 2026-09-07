<?php

function schema_blueprint(): Blueprint
{
    $table = new Blueprint('posts', true);
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('title', 150)->unique();
    $table->string('slug')->nullable()->index();
    $table->text('body')->nullable();
    $table->enum('status', ['draft', 'live'])->default('draft');
    $table->decimal('price', 10, 2)->unsigned()->default(0);
    $table->boolean('featured')->default(false)->comment('Homepage flag');
    $table->json('meta')->nullable();
    $table->unsignedInteger('views')->default(0);
    $table->dateTime('published_at')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->index(['status', 'published_at']);

    return $table;
}

test('MySQL create table', function () {
    $sql = (new SchemaGrammar(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']))->compile(schema_blueprint());

    assert_count(1, $sql);
    $ddl = $sql[0];

    assert_contains('CREATE TABLE `posts` (', $ddl);
    assert_contains('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $ddl);
    assert_contains('`user_id` INT UNSIGNED NOT NULL', $ddl);
    assert_contains('`title` VARCHAR(150) NOT NULL', $ddl);
    assert_contains('`slug` VARCHAR(255) NULL', $ddl);
    assert_contains("`status` ENUM('draft', 'live') NOT NULL DEFAULT 'draft'", $ddl);
    assert_contains('`price` DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0', $ddl);
    assert_contains("`featured` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Homepage flag'", $ddl);
    assert_contains('`meta` JSON NULL', $ddl);
    assert_contains('`created_at` DATETIME NULL', $ddl);
    assert_contains('`deleted_at` DATETIME NULL', $ddl);
    assert_contains('UNIQUE `posts_title_unique` (`title`)', $ddl);
    assert_contains('INDEX `posts_slug_index` (`slug`)', $ddl);
    assert_contains('INDEX `posts_status_published_at_index` (`status`, `published_at`)', $ddl);
    assert_contains('CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', $ddl);
    assert_contains('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $ddl);
});

test('MySQL alter table emits one statement per change', function () {
    $table = new Blueprint('posts', false);
    $table->string('subtitle')->nullable()->after('title');
    $table->string('title', 200)->change();
    $table->unique('slug');
    $table->dropColumn(['body', 'meta']);
    $table->dropIndex(['status', 'published_at']);
    $table->dropUnique('posts_title_unique');
    $table->dropForeign(['user_id']);
    $table->renameColumn('views', 'hits');
    $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();

    $sql = (new SchemaGrammar())->compile($table);

    assert_same([
        'ALTER TABLE `posts` ADD `subtitle` VARCHAR(255) NULL AFTER `title`',
        'ALTER TABLE `posts` MODIFY `title` VARCHAR(200) NOT NULL',
        'ALTER TABLE `posts` ADD UNIQUE `posts_slug_unique` (`slug`)',
        'ALTER TABLE `posts` ADD CONSTRAINT `posts_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL',
        'ALTER TABLE `posts` DROP COLUMN `body`',
        'ALTER TABLE `posts` DROP COLUMN `meta`',
        'ALTER TABLE `posts` DROP INDEX `posts_status_published_at_index`',
        'ALTER TABLE `posts` DROP INDEX `posts_title_unique`',
        'ALTER TABLE `posts` DROP FOREIGN KEY `posts_user_id_foreign`',
        'ALTER TABLE `posts` RENAME COLUMN `views` TO `hits`',
    ], $sql);
});

test('MySQL drop and rename', function () {
    $grammar = new SchemaGrammar();

    assert_same('DROP TABLE `posts`', $grammar->compileDrop('posts'));
    assert_same('DROP TABLE IF EXISTS `posts`', $grammar->compileDropIfExists('posts'));
    assert_same('RENAME TABLE `posts` TO `articles`', $grammar->compileRename('posts', 'articles'));
});

test('SQLite create table uses INTEGER PRIMARY KEY and separate index statements', function () {
    $sql = (new SqliteSchemaGrammar())->compile(schema_blueprint());

    assert_count(4, $sql);
    $ddl = $sql[0];

    assert_contains('`id` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL', $ddl);
    assert_contains('`user_id` INTEGER NOT NULL', $ddl);
    assert_contains("`status` VARCHAR(255) CHECK (`status` IN ('draft', 'live')) NOT NULL DEFAULT 'draft'", $ddl);
    assert_contains('`price` NUMERIC(10, 2) NOT NULL DEFAULT 0', $ddl);
    assert_contains('`meta` TEXT NULL', $ddl);
    assert_contains('FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE', $ddl);
    assert_not_contains('ENGINE', $ddl);
    assert_not_contains('COMMENT', $ddl);
    assert_same('CREATE UNIQUE INDEX `posts_title_unique` ON `posts` (`title`)', $sql[1]);
    assert_same('CREATE INDEX `posts_slug_index` ON `posts` (`slug`)', $sql[2]);
    assert_same('CREATE INDEX `posts_status_published_at_index` ON `posts` (`status`, `published_at`)', $sql[3]);
});

test('SQLite alter supports adding columns and indexes but not modifying', function () {
    $table = new Blueprint('posts', false);
    $table->string('subtitle')->nullable();
    $table->index('subtitle');
    $table->dropColumn('body');
    $table->renameColumn('views', 'hits');

    assert_same([
        'ALTER TABLE `posts` ADD COLUMN `subtitle` VARCHAR(255) NULL',
        'CREATE INDEX `posts_subtitle_index` ON `posts` (`subtitle`)',
        'ALTER TABLE `posts` DROP COLUMN `body`',
        'ALTER TABLE `posts` RENAME COLUMN `views` TO `hits`',
    ], (new SqliteSchemaGrammar())->compile($table));

    $change = new Blueprint('posts', false);
    $change->string('title', 10)->change();
    assert_throws(fn () => (new SqliteSchemaGrammar())->compile($change), RuntimeException::class, 'modify');

    assert_same('ALTER TABLE `a` RENAME TO `b`', (new SqliteSchemaGrammar())->compileRename('a', 'b'));
});

test('index names follow the table_columns_type convention', function () {
    $table = new Blueprint('post_tag', true);

    assert_same('post_tag_post_id_tag_id_unique', $table->indexName('unique', ['post_id', 'tag_id']));
    assert_same('post_tag_post_id_foreign', $table->indexName('foreign', ['post_id']));
});

test('constrained() guesses the referenced table from the column name', function () {
    $table = new Blueprint('comments', true);
    $table->foreignId('post_id')->constrained();
    $table->foreignId('author_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();

    $ddl = (new SchemaGrammar())->compile($table)[0];

    assert_contains('FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`)', $ddl);
    assert_contains('FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE', $ddl);
});
