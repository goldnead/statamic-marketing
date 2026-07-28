<?php

namespace Goldnead\Marketing\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bed for migrating a database by hand, from any released schema forward.
 *
 * The rest of the suite runs against a database that `RefreshDatabase` has
 * already migrated to head, which is the one shape a migration can never be
 * wrong about. Everything here needs the opposite: an empty database, an
 * arbitrary earlier release installed into it, rows put in, and then the
 * migrations run one at a time with the tables no longer empty.
 *
 * That cannot share the suite's connection. `RefreshDatabase` wraps every test
 * in a transaction, and DDL under MySQL commits implicitly — a `migrate` run
 * inside that transaction would end it and leak its tables into every test
 * that followed. So these tests get a connection of their own, outside
 * anything the trait manages: a temp-file SQLite database by default, and a
 * second throwaway schema beside the configured one when the suite is pointed
 * at MySQL (see phpunit.mysql.xml). It is torn down between tests either way.
 */
abstract class MigrationPathTestCase extends TestCase
{
    /**
     * The name of the isolated connection these tests migrate.
     */
    protected const CONNECTION = 'migration_path';

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->dropIsolatedSqliteFile();

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.'.self::CONNECTION, $this->isolatedConnection());

        // A server-level handle with no database selected, used for nothing but
        // `create database`. Issuing that on the suite's own connection would
        // implicitly commit the transaction RefreshDatabase is holding open,
        // and every test after this one would roll back into nothing.
        $app['config']->set('database.connections.'.self::CONNECTION.'_server', [
            ...$this->isolatedConnection(),
            'database' => null,
        ]);
    }

    /**
     * Mirrors TestCase::testingConnection(), so these tests exercise the same
     * engine the rest of the run does — including the MySQL run, where the
     * index rules that SQLite does not have are the whole point.
     */
    protected function isolatedConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => $this->isolatedSqlitePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'marketing_test').'_migration_path',
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    protected function isolatedSqlitePath(): string
    {
        return sys_get_temp_dir().'/marketing-migration-path-'.getmypid().'.sqlite';
    }

    /**
     * Empty database, brand-context installed, nothing of this addon's own.
     *
     * `brands` is a hard precondition: the brand-scoping migration backfills
     * every row onto the default brand and needs one to exist.
     */
    protected function resetIsolatedDatabase(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            $this->dropIsolatedSqliteFile();
            touch($this->isolatedSqlitePath());
        } else {
            $database = env('DB_DATABASE', 'marketing_test').'_migration_path';

            DB::connection(self::CONNECTION.'_server')->statement(
                'create database if not exists `'.$database.'` character set utf8mb4 collate utf8mb4_unicode_ci'
            );

            DB::purge(self::CONNECTION.'_server');
        }

        DB::purge(self::CONNECTION);

        Schema::connection(self::CONNECTION)->dropAllTables();

        DB::purge(self::CONNECTION);

        $this->migratePath(__DIR__.'/../vendor/goldnead/statamic-brand-context/database/migrations');
    }

    protected function dropIsolatedSqliteFile(): void
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            DB::purge(self::CONNECTION);

            if (file_exists($this->isolatedSqlitePath())) {
                @unlink($this->isolatedSqlitePath());
            }
        }
    }

    /**
     * Run every not-yet-run migration in a directory against the isolated
     * connection. Failures are not swallowed: the point of these tests is what
     * happens when one throws.
     */
    protected function migratePath(string $path): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database' => self::CONNECTION,
            '--path' => $path,
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    /**
     * Run the migrations in a directory one file at a time, handing control
     * back between each so a caller can put rows in first.
     *
     * @param  callable(string): void|null  $before  receives the migration name
     */
    protected function migrateStepwise(string $path, ?callable $before = null): void
    {
        foreach ($this->migrationFilesIn($path) as $file) {
            if ($before) {
                $before(basename($file, '.php'));
            }

            $this->migratePath($file);
        }
    }

    /**
     * @return list<string>
     */
    protected function migrationFilesIn(string $path): array
    {
        $files = glob(rtrim($path, '/').'/*.php') ?: [];

        sort($files);

        return $files;
    }

    protected function releasedMigrations(string $version): string
    {
        return __DIR__.'/Fixtures/released-migrations/'.$version;
    }

    protected function currentMigrations(): string
    {
        return __DIR__.'/../database/migrations';
    }

    protected function isolated(): \Illuminate\Database\Connection
    {
        return DB::connection(self::CONNECTION);
    }

    protected function isolatedSchema(): \Illuminate\Database\Schema\Builder
    {
        return Schema::connection(self::CONNECTION);
    }

    /**
     * The migration names the isolated database has recorded as run.
     *
     * @return list<string>
     */
    protected function ranMigrations(): array
    {
        if (! $this->isolatedSchema()->hasTable('migrations')) {
            return [];
        }

        return $this->isolated()->table('migrations')->pluck('migration')->all();
    }

    /**
     * Write a second consent record for a list/address pair, and keep it.
     *
     * Only possible while the unique is missing, which is the situation this
     * models: a live sign-up form that carried on working after a failed
     * update.
     */
    protected function writeDuplicate(string $listHandle, string $email): int
    {
        $existing = (array) $this->isolated()->table('marketing_subscriptions')
            ->where('list_handle', $listHandle)
            ->where('email_normalized', $email)
            ->firstOrFail();

        $row = collect($existing)
            ->except('id')
            ->put('uuid', (string) \Illuminate\Support\Str::uuid())
            ->put('token', bin2hex(random_bytes(16)))
            ->put('created_at', now())
            ->put('updated_at', now())
            ->all();

        return (int) $this->isolated()->table('marketing_subscriptions')->insertGetId($row);
    }

    /**
     * Whether a duplicate consent record can be written for a list/address
     * pair that already has one.
     *
     * This is the assertion the whole file exists for, and it is deliberately
     * behavioural. "The migration ran" and "the constraint is there" are not
     * the same statement, and confusing the two is what let a release ship
     * with the consent unique dropped and not replaced. An index by name can
     * exist over the wrong columns, over a nullable column, or not bite at
     * all; the only thing that settles it is trying to write the row the
     * constraint is supposed to refuse.
     */
    protected function duplicateConsentIsAccepted(string $listHandle, string $email): bool
    {
        $existing = $this->isolated()->table('marketing_subscriptions')
            ->where('list_handle', $listHandle)
            ->where('email_normalized', $email)
            ->first();

        if (! $existing) {
            throw new \RuntimeException("No subscription for [{$email}] on [{$listHandle}] to duplicate.");
        }

        $row = collect((array) $existing)
            ->except('id')
            ->put('uuid', (string) \Illuminate\Support\Str::uuid())
            ->put('token', bin2hex(random_bytes(16)))
            ->all();

        try {
            $id = $this->isolated()->table('marketing_subscriptions')->insertGetId($row);
        } catch (\Illuminate\Database\QueryException) {
            return false;
        }

        $this->isolated()->table('marketing_subscriptions')->where('id', $id)->delete();

        return true;
    }
}
