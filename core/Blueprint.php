<?php

// Describes a table for Schema::create() / Schema::table(). Column methods
// return a ColumnDefinition for modifiers; index/foreign/drop methods record
// commands. A SchemaGrammar turns the blueprint into SQL for the driver.
//
//   Schema::create('posts', function (Blueprint $table) {
//       $table->id();
//       $table->foreignId('user_id')->constrained()->cascadeOnDelete();
//       $table->string('title');
//       $table->text('body')->nullable();
//       $table->boolean('published')->default(false)->index();
//       $table->timestamps();
//       $table->softDeletes();
//   });

class Blueprint
{
    public array $columns = [];
    public array $commands = [];
    public ?string $engine = null;
    public ?string $charset = null;
    public ?string $collation = null;

    public function __construct(public string $table, public bool $creating = false)
    {
    }

    public function addColumn(string $type, string $name, array $parameters = []): ColumnDefinition
    {
        return $this->columns[] = new ColumnDefinition($type, $name, $parameters, $this);
    }

    // ----------------------------------------------------------- integers --

    // INT UNSIGNED AUTO_INCREMENT PRIMARY KEY — matches foreignId().
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->increments($name);
    }

    public function increments(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name)->unsigned()->autoIncrement()->primary();
    }

    public function bigIncrements(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name)->unsigned()->autoIncrement()->primary();
    }

    public function smallIncrements(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name)->unsigned()->autoIncrement()->primary();
    }

    public function tinyIncrements(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name)->unsigned()->autoIncrement()->primary();
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    public function mediumInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumInteger', $name);
    }

    public function unsignedMediumInteger(string $name): ColumnDefinition
    {
        return $this->mediumInteger($name)->unsigned();
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    public function unsignedSmallInteger(string $name): ColumnDefinition
    {
        return $this->smallInteger($name)->unsigned();
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    public function unsignedTinyInteger(string $name): ColumnDefinition
    {
        return $this->tinyInteger($name)->unsigned();
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    // INT UNSIGNED, ready for ->constrained().
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->unsignedInteger($name);
    }

    public function foreignIdFor(string $model, ?string $column = null): ColumnDefinition
    {
        return $this->foreignId($column ?? $model::foreignKey());
    }

    // ------------------------------------------------------------ strings --

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    public function char(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('char', $name, ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumText', $name);
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    public function uuid(string $name = 'uuid'): ColumnDefinition
    {
        return $this->addColumn('uuid', $name);
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn('binary', $name);
    }

    public function enum(string $name, array $allowed): ColumnDefinition
    {
        return $this->addColumn('enum', $name, ['allowed' => array_values($allowed)]);
    }

    public function ipAddress(string $name = 'ip_address'): ColumnDefinition
    {
        return $this->addColumn('ipAddress', $name);
    }

    public function macAddress(string $name = 'mac_address'): ColumnDefinition
    {
        return $this->addColumn('macAddress', $name);
    }

    // ------------------------------------------------------------ numbers --

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn('float', $name);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn('double', $name);
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    public function unsignedDecimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->decimal($name, $precision, $scale)->unsigned();
    }

    // -------------------------------------------------------------- dates --

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    public function dateTime(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('dateTime', $name, ['precision' => $precision]);
    }

    public function timestamp(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name, ['precision' => $precision]);
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn('time', $name);
    }

    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn('year', $name);
    }

    // created_at / updated_at, nullable DATETIME (models fill them).
    public function timestamps(int $precision = 0): void
    {
        $this->dateTime('created_at', $precision)->nullable();
        $this->dateTime('updated_at', $precision)->nullable();
    }

    public function nullableTimestamps(int $precision = 0): void
    {
        $this->timestamps($precision);
    }

    public function softDeletes(string $column = 'deleted_at', int $precision = 0): ColumnDefinition
    {
        return $this->dateTime($column, $precision)->nullable();
    }

    public function rememberToken(): ColumnDefinition
    {
        return $this->string('remember_token', 100)->nullable();
    }

    // ------------------------------------------------------------ indexes --

    public function primary(string|array $columns, ?string $name = null): static
    {
        return $this->addCommand('primary', ['columns' => (array) $columns, 'name' => $name]);
    }

    public function unique(string|array $columns, ?string $name = null): static
    {
        return $this->addCommand('unique', ['columns' => (array) $columns, 'name' => $name]);
    }

    public function index(string|array $columns, ?string $name = null): static
    {
        return $this->addCommand('index', ['columns' => (array) $columns, 'name' => $name]);
    }

    public function foreign(string|array $columns, ?string $name = null): ForeignKeyDefinition
    {
        $definition = new ForeignKeyDefinition($columns, $name);

        $this->addCommand('foreign', ['definition' => $definition]);

        return $definition;
    }

    // ------------------------------------------------------------- drops --

    public function dropColumn(string|array $columns): static
    {
        return $this->addCommand('dropColumn', ['columns' => (array) $columns]);
    }

    public function dropIndex(string|array $index): static
    {
        return $this->addCommand('dropIndex', ['name' => $this->resolveIndexName('index', $index)]);
    }

    public function dropUnique(string|array $index): static
    {
        return $this->addCommand('dropUnique', ['name' => $this->resolveIndexName('unique', $index)]);
    }

    public function dropPrimary(): static
    {
        return $this->addCommand('dropPrimary', []);
    }

    public function dropForeign(string|array $index): static
    {
        return $this->addCommand('dropForeign', ['name' => $this->resolveIndexName('foreign', $index)]);
    }

    public function dropSoftDeletes(string $column = 'deleted_at'): static
    {
        return $this->dropColumn($column);
    }

    public function dropTimestamps(): static
    {
        return $this->dropColumn(['created_at', 'updated_at']);
    }

    public function dropRememberToken(): static
    {
        return $this->dropColumn('remember_token');
    }

    public function renameColumn(string $from, string $to): static
    {
        return $this->addCommand('renameColumn', ['from' => $from, 'to' => $to]);
    }

    public function rename(string $to): static
    {
        return $this->addCommand('rename', ['to' => $to]);
    }

    // ------------------------------------------------------------ options --

    public function engine(string $engine): static
    {
        $this->engine = $engine;

        return $this;
    }

    public function charset(string $charset): static
    {
        $this->charset = $charset;

        return $this;
    }

    public function collation(string $collation): static
    {
        $this->collation = $collation;

        return $this;
    }

    // ------------------------------------------------------------- helpers --

    protected function addCommand(string $type, array $parameters): static
    {
        $this->commands[] = ['type' => $type] + $parameters;

        return $this;
    }

    // "{table}_{col1}_{col2}_{type}" — the convention drop*() relies on.
    public function indexName(string $type, array $columns): string
    {
        return strtolower($this->table . '_' . implode('_', $columns) . '_' . $type);
    }

    private function resolveIndexName(string $type, string|array $index): string
    {
        return is_array($index) ? $this->indexName($type, $index) : $index;
    }

    public function toSql(SchemaGrammar $grammar): array
    {
        return $grammar->compile($this);
    }
}
