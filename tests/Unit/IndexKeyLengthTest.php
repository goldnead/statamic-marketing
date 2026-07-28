<?php

use Illuminate\Support\Facades\DB;

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

    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('key_length_probe');

    // pretend() renders every logged statement with its bindings substituted,
    // and substituting a *string* binding goes through PDO::quote. The brand
    // backfill in 2026_07_24_100001 looks a handle up, so without a PDO the
    // probe would try to reach a MySQL server after all — for the query log,
    // not for the query. A throwaway SQLite handle quotes strings and is never
    // asked to run anything: pretending() short-circuits every statement
    // before it reaches the driver, and the grammar stays MySQL's, which is
    // the thing being measured.
    DB::connection('key_length_probe')->setPdo(new PDO('sqlite::memory:'));

    try {
        // pretend() short-circuits every statement before a PDO instance is
        // needed, so this compiles the DDL without a server anywhere in sight.
        $queries = DB::connection('key_length_probe')->pretend(function () {
            foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
                (require $file)->up();
            }
        });
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('key_length_probe');
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
