<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\MySqlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What MySQL would make of this addon's migrations, measured without MySQL.
 *
 * Ported from statamic-notifications v1.0.4, where an index of 3212 bytes took
 * two tables down on production while the suite stayed green through four
 * releases. The suite could not have found it: it runs on in-memory SQLite,
 * which has no InnoDB key-length limit, stores no fixed column widths (it
 * accepts `varchar(255)` and ignores the 255) and has no per-character byte
 * cost to multiply. The arithmetic that fails on MySQL does not exist there.
 *
 * So this test does not ask the database. It compiles the addon's own
 * migration files through Laravel's MySQL grammar in pretend mode — no server,
 * no connection, nothing to install in CI — and measures the DDL MySQL would
 * have received. It reads the real migration files, so it cannot drift from
 * them, and it fails on the next oversized index rather than on the next
 * deploy.
 *
 * It also asserts the second, quieter property: a SQL unique does not constrain
 * NULL, on any engine. An index over a nullable column enforces nothing for the
 * rows where that column is NULL — which is how notifications ended up with a
 * whole class of recipient that never had a uniqueness guarantee at all.
 */
const INNODB_MAX_KEY_BYTES = 3072;

it('keeps every index the migrations create inside the InnoDB key limit', function () {
    $schema = compileMigrationsForMysql();

    expect($schema['indexes'])->not->toBeEmpty();

    foreach ($schema['indexes'] as $index) {
        $bytes = 0;

        foreach ($index['columns'] as $column) {
            $width = $schema['columns'][$index['table']][$column]['bytes'] ?? null;

            expect($width)->not->toBeNull(
                "Index {$index['name']} covers unknown column {$column}."
            );

            $bytes += $width;
        }

        expect($bytes)->toBeLessThanOrEqual(
            INNODB_MAX_KEY_BYTES,
            "Index {$index['name']} on {$index['table']} needs {$bytes} bytes under utf8mb4; ".
            'InnoDB allows '.INNODB_MAX_KEY_BYTES.'. MySQL would refuse this migration with SQLSTATE 1071.'
        );
    }
});

it('still spends less than half the key limit, leaving room for another column', function () {
    // Being under the limit by accident is what makes a schema fragile. The
    // consent unique sat at 2048 of 3072 bytes: MySQL accepted it, and the next
    // varchar added to it would not have been accepted. Headroom is asserted
    // rather than hoped for.
    $schema = compileMigrationsForMysql();

    foreach ($schema['indexes'] as $index) {
        $bytes = collect($index['columns'])
            ->sum(fn ($column) => $schema['columns'][$index['table']][$column]['bytes'] ?? 0);

        expect($bytes)->toBeLessThan(
            INNODB_MAX_KEY_BYTES / 2,
            "Index {$index['name']} on {$index['table']} uses {$bytes} bytes — over half the limit, ".
            'so the next column added to it is likely to break the migration.'
        );
    }
});

it('builds every unique out of columns that cannot be NULL', function () {
    // A unique index ignores NULL. Where a covered column is nullable, the
    // constraint simply does not apply to those rows — the index is present,
    // reads as an enforced rule, and enforces nothing. Anything a unique is
    // meant to guarantee has to be expressed in a NOT NULL column.
    $schema = compileMigrationsForMysql();

    $uniques = collect($schema['indexes'])->where('unique', true);

    expect($uniques)->not->toBeEmpty();

    foreach ($uniques as $index) {
        foreach ($index['columns'] as $column) {
            expect($schema['columns'][$index['table']][$column]['nullable'] ?? true)->toBeFalse(
                "Unique {$index['name']} on {$index['table']} covers nullable column {$column}. ".
                'A unique does not constrain NULL, so for every row where that column is null the '.
                'index guarantees nothing — including the uniqueness its name claims.'
            );
        }
    }
});

it('enforces the consent uniqueness through a fixed-width key', function () {
    // The uniqueness that matters most in this addon: one subscription per
    // address per list per brand. It must survive the shrink — the wide natural
    // tuple is replaced by a hash of it, not by a prefix of it. A prefix would
    // fit just as well and would quietly treat two different lists as one.
    $schema = compileMigrationsForMysql();

    $uniques = collect($schema['indexes'])->where('unique', true)->keyBy('name');

    expect($uniques)->toHaveKey('ms_brand_list_email_unique')
        ->and($uniques['ms_brand_list_email_unique']['columns'])->toBe(['brand_id', 'uniqueness_key']);

    // brand_id stays a column of the index rather than an ingredient of the
    // hash, so the tenant boundary is still expressed in the schema and still
    // usable as a range.
    expect($uniques['ms_brand_list_email_unique']['columns'][0])->toBe('brand_id');
});

it('keeps list handles unique across all brands', function () {
    // The public subscribe endpoint derives the brand from the list handle, so
    // this one unique must not be brand-scoped. v1.6.0 restored it; measuring
    // the schema is also the place to keep it.
    $schema = compileMigrationsForMysql();

    $global = collect($schema['indexes'])
        ->where('unique', true)
        ->where('table', 'marketing_lists')
        ->first(fn ($index) => $index['columns'] === ['handle']);

    expect($global)->not->toBeNull(
        'marketing_lists.handle is no longer unique across all brands. SetBrandFromListHandle '.
        'resolves a brand from it, and a per-brand unique would let two brands own the same '.
        'handle — every public sign-up for it then raises AmbiguousBrandRecord.'
    );
});

/**
 * Runs every migration in the addon against a MySQL connection that is never
 * opened, and returns the column definitions and index definitions MySQL would
 * see after the last migration.
 *
 * Two connections, because a migration that branches on the schema needs both
 * halves and no single connection can give them:
 *
 * - the **probe** compiles the DDL. Its grammar is MySQL's, `pretend()` stops
 *   every statement before it reaches a driver, and the rendered SQL is what
 *   gets measured. It has no server and no schema of its own — under
 *   `pretend()` a `select` returns an empty array, so anything asked of it
 *   about the current schema comes back as "nothing is there".
 * - the **state** is a real SQLite database that the same migrations are run
 *   against for real, one file behind. It is what `Schema::hasColumn()`,
 *   `Schema::getIndexes()` and `Schema::getColumns()` are answered from.
 *
 * That split is not incidental. `2026_07_24_100001` asks whether
 * `uniqueness_key` exists before deciding which consent unique to build, and
 * `replaceUnique()` asks which indexes are present before dropping any — a
 * probe that answers "nothing is there" to both would measure a schema no
 * install ever has, and would have gone green on the 1.6.1 defect for the same
 * reason every other test did.
 *
 * The two run interleaved, probe first: the DDL for migration N is compiled
 * against the schema as it stood after N-1, which is exactly what the server
 * sees.
 *
 * @return array{columns: array<string, array<string, array{bytes: int, nullable: bool}>>, indexes: list<array{table: string, name: string, unique: bool, columns: list<string>}>}
 */
function compileMigrationsForMysql(): array
{
    config()->set('database.connections.key_length_probe', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'key_length_probe',
        'username' => 'probe',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    config()->set('database.connections.key_length_state', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    $previous = DB::getDefaultConnection();

    DB::purge('key_length_probe');
    DB::purge('key_length_state');

    $probe = DB::connection('key_length_probe');
    $state = DB::connection('key_length_state');

    // pretend() renders every logged statement with its bindings substituted,
    // and substituting a *string* binding goes through PDO::quote. The brand
    // backfill in 2026_07_24_100001 looks a handle up, so without a PDO the
    // probe would try to reach a MySQL server after all — for the query log,
    // not for the query. A throwaway SQLite handle quotes strings and is never
    // asked to run anything: pretending() short-circuits every statement
    // before it reaches the driver, and the grammar stays MySQL's, which is
    // the thing being measured.
    $probe->setPdo(new PDO('sqlite::memory:'));

    // The brand every existing row is backfilled onto. brand-context creates
    // this table; the state database only needs enough of it to answer the
    // lookup.
    $state->getSchemaBuilder()->create('brands', function ($table) {
        $table->id();
        $table->string('handle');
        $table->boolean('is_default')->default(false);
    });

    $state->table('brands')->insert(['handle' => 'default', 'is_default' => true]);

    // A connection resolves its schema grammar lazily, inside
    // getSchemaBuilder(). The oracle is constructed directly, so it has to be
    // asked for explicitly or every Blueprint the probe compiles gets a null
    // grammar.
    $probe->useDefaultSchemaGrammar();

    $oracle = new ProbeSchemaBuilder($probe, $state);

    $queries = [];

    try {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
            $migration = require $file;

            // 1. What MySQL would be sent, decided on the schema as it stands.
            DB::setDefaultConnection('key_length_probe');
            app()->instance('db.schema', $oracle);
            Schema::clearResolvedInstance('db.schema');

            $queries = array_merge($queries, $probe->pretend(fn () => $migration->up()));

            // 2. Advance the real schema, so the next file branches on truth.
            DB::setDefaultConnection('key_length_state');
            app()->forgetInstance('db.schema');
            Schema::clearResolvedInstance('db.schema');

            $migration->up();
        }
    } finally {
        DB::setDefaultConnection($previous);
        app()->forgetInstance('db.schema');
        Schema::clearResolvedInstance('db.schema');
        DB::purge('key_length_probe');
        DB::purge('key_length_state');
    }

    $columns = [];
    $indexes = [];

    foreach (array_column($queries, 'query') as $sql) {
        if (preg_match('/^create table `(\w+)` \((.*)\)(?: default character set| collate|$)/s', $sql, $match)) {
            foreach (splitTopLevel($match[2]) as $definition) {
                if (preg_match('/^`(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = describeMysqlColumn($column[2]);
                }
            }

            continue;
        }

        // Columns added later (`Schema::table(…)->…`) and columns redefined by
        // `->change()`, which MySQL compiles to `modify`. Both overwrite what
        // the create-table statement said, so the last word wins — that is the
        // shape the index is finally built on.
        if (preg_match('/^alter table `(\w+)` ((?:add|modify) .+)$/', $sql, $match)) {
            foreach (splitTopLevel($match[2]) as $definition) {
                if (preg_match('/^(?:add|modify) `(\w+)` (.+)$/', trim($definition), $column)) {
                    $columns[$match[1]][$column[1]] = describeMysqlColumn($column[2]);
                }
            }
        }

        // Keyed by name, so an index that is rebuilt later in the chain counts
        // once, in the shape the last migration left it — which is the shape
        // the server ends up holding.
        if (preg_match('/^alter table `(\w+)` add (unique|index) `(\w+)`\((.+)\)$/', $sql, $match)) {
            $indexes[$match[1].'.'.$match[3]] = [
                'table' => $match[1],
                'name' => $match[3],
                'unique' => $match[2] === 'unique',
                'columns' => array_map(
                    fn ($column) => trim($column, ' `'),
                    explode(',', $match[4])
                ),
            ];
        }

        if (preg_match('/^alter table `(\w+)` drop index `(\w+)`$/', $sql, $match)) {
            unset($indexes[$match[1].'.'.$match[2]]);
        }
    }

    return ['columns' => $columns, 'indexes' => array_values($indexes)];
}

/**
 * Compiles against MySQL's grammar, answers questions from a real database.
 *
 * Everything that writes goes to the probe connection and is measured.
 * Everything that reads is delegated, because the probe has nothing to read:
 * it has no schema, and `pretend()` would answer "empty" to every question a
 * migration asks about the one it is modifying.
 */
class ProbeSchemaBuilder extends MySqlBuilder
{
    public function __construct(
        Connection $probe,
        private Connection $state,
    ) {
        parent::__construct($probe);
    }

    public function hasTable($table)
    {
        return $this->state->getSchemaBuilder()->hasTable($table);
    }

    public function hasColumn($table, $column)
    {
        return $this->state->getSchemaBuilder()->hasColumn($table, $column);
    }

    public function hasColumns($table, $columns)
    {
        return $this->state->getSchemaBuilder()->hasColumns($table, $columns);
    }

    public function getTables($schema = null)
    {
        return $this->state->getSchemaBuilder()->getTables();
    }

    public function getColumns($table)
    {
        return $this->state->getSchemaBuilder()->getColumns($table);
    }

    public function getIndexes($table)
    {
        return $this->state->getSchemaBuilder()->getIndexes($table);
    }
}

/** Splits a definition list on commas that are not inside parentheses. */
function splitTopLevel(string $list): array
{
    $parts = [];
    $depth = 0;
    $buffer = '';

    foreach (str_split($list) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    return array_merge($parts, [$buffer]);
}

/**
 * Worst-case index bytes and nullability for one compiled column definition.
 *
 * @return array{bytes: int, nullable: bool}
 */
function describeMysqlColumn(string $type): array
{
    return [
        'bytes' => mysqlIndexBytes($type),
        // Laravel's MySQL grammar always states one or the other, and `not
        // null` is what a NOT NULL column reads as. Anything else is nullable.
        'nullable' => ! str_contains($type, 'not null'),
    ];
}

/** Worst-case bytes this column type occupies in an index under utf8mb4. */
function mysqlIndexBytes(string $type): int
{
    if (preg_match('/^(?:var)?char\((\d+)\)/', $type, $match)) {
        return (int) $match[1] * 4;
    }

    return match (true) {
        str_starts_with($type, 'tinyint') => 1,
        str_starts_with($type, 'smallint') => 2,
        str_starts_with($type, 'mediumint') => 3,
        str_starts_with($type, 'int') => 4,
        str_starts_with($type, 'bigint') => 8,
        str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => 8,
        str_starts_with($type, 'date') => 3,
        // Blobs and JSON cannot be indexed whole at all. Reported as oversized
        // so an index that reaches for one fails here rather than on MySQL.
        default => INNODB_MAX_KEY_BYTES + 1,
    };
}
