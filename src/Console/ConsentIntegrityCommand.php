<?php

namespace Goldnead\Marketing\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answers the one question a failed update leaves open: is one address on one
 * list still one consent record?
 *
 * `php artisan migrate` reporting success means the migrations ran. It does
 * not mean the constraints they were supposed to leave behind are there —
 * and under 1.6.1, 1.6.2 and 1.6.3 an install created before 1.3.0 could end
 * up with the consent unique dropped, its replacement never built, and the
 * migration not recorded as run. Nothing about that state announces itself.
 * The sign-up form keeps working; it just stops refusing duplicates.
 *
 * So this command does not ask whether a migration ran. It reads the indexes
 * that are on the table right now, and it reads the rows.
 *
 * It never deletes anything. Where duplicates exist they are real sign-ups
 * that a form accepted while the constraint was missing, and deciding which of
 * them is *the* consent record — the one whose confirmation timestamp and
 * source the install will stand behind if anybody asks — is not a decision a
 * command gets to make. `--repair` rebuilds the index and nothing else, and
 * refuses to run while there is anything for it to reject.
 */
class ConsentIntegrityCommand extends Command
{
    protected $signature = 'marketing:consent-integrity
        {--database= : The connection to inspect (default: the configured one)}
        {--repair : Rebuild the consent unique, if and only if nothing would have to be deleted for it}';

    protected $description = 'Check that one address on one list is still one consent record, and say so plainly';

    private const TABLE = 'marketing_subscriptions';

    private const BRAND_INDEX = 'ms_brand_list_email_unique';

    private const GLOBAL_INDEX = 'marketing_subscriptions_list_handle_email_normalized_unique';

    public function handle(): int
    {
        $connection = DB::connection($this->option('database') ?: null);
        $schema = Schema::connection($this->option('database') ?: null);

        if (! $schema->hasTable(self::TABLE)) {
            $this->components->info('There is no '.self::TABLE.' table on this connection; nothing to check.');

            return self::SUCCESS;
        }

        $hasBrand = $schema->hasColumn(self::TABLE, 'brand_id');
        $hasKey = $schema->hasColumn(self::TABLE, 'uniqueness_key');

        [$expectedName, $expectedColumns] = $this->expectedIndex($hasBrand, $hasKey);

        $indexes = collect($schema->getIndexes(self::TABLE))->keyBy('name');
        $present = $indexes->get(self::BRAND_INDEX) ?? $indexes->get(self::GLOBAL_INDEX);

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>table</>', self::TABLE);
        $this->components->twoColumnDetail('<fg=gray>rows</>', (string) $connection->table(self::TABLE)->count());
        $this->components->twoColumnDetail('<fg=gray>brand_id column</>', $hasBrand ? 'yes' : 'no');
        $this->components->twoColumnDetail('<fg=gray>uniqueness_key column</>', $hasKey ? 'yes' : 'no');
        $this->components->twoColumnDetail(
            '<fg=gray>consent index</>',
            $present ? $present['name'].' ('.implode(', ', $present['columns']).')' : '<fg=red>none</>'
        );

        $duplicates = $this->duplicates($connection, $hasBrand);

        if (! $present) {
            $this->line('');
            $this->components->error(
                'There is no unique index protecting (list_handle, email_normalized) on this table. '
                .'The same address can be signed up to the same list twice, and has been able to since '
                .'the update that dropped it.'
            );
        } elseif ($present['columns'] !== $expectedColumns) {
            $this->line('');
            $this->components->warn(sprintf(
                'The consent index is [%s] over (%s); this schema expects (%s). Run `php artisan migrate`.',
                $present['name'],
                implode(', ', $present['columns']),
                implode(', ', $expectedColumns),
            ));
        }

        if ($hasBrand && $this->nullBrands($connection) > 0) {
            $this->components->warn(sprintf(
                '%d row(s) have no brand_id. Every consent index leads with that column and no engine '
                .'constrains a NULL, so those rows are unprotected whatever the index says. '
                .'`php artisan migrate` backfills them.',
                $this->nullBrands($connection),
            ));
        }

        $this->reportDuplicates($duplicates);

        if ($this->option('repair')) {
            return $this->repair($schema, $present, $expectedName, $expectedColumns, $duplicates->isNotEmpty());
        }

        if ($present && $duplicates->isEmpty() && $present['columns'] === $expectedColumns) {
            $this->line('');
            $this->components->info('One address, one list, one consent record — enforced by the database.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->components->bulletList(array_filter([
            $duplicates->isNotEmpty()
                ? 'Decide which of the rows above is the consent record and delete the others. Nothing else can.'
                : null,
            'Then run `php artisan migrate`, which rebuilds the index and finishes the interrupted update.',
            'Or run this command again with --repair to rebuild only the index.',
        ]));

        return self::FAILURE;
    }

    /**
     * The index this schema version is supposed to be carrying.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function expectedIndex(bool $hasBrand, bool $hasKey): array
    {
        if (! $hasBrand) {
            return [self::GLOBAL_INDEX, ['list_handle', 'email_normalized']];
        }

        return $hasKey
            ? [self::BRAND_INDEX, ['brand_id', 'uniqueness_key']]
            : [self::BRAND_INDEX, ['brand_id', 'list_handle', 'email_normalized']];
    }

    /**
     * Sign-ups that share a list and an address inside a brand.
     *
     * Measured on the natural columns, never on `uniqueness_key`. The hash is a
     * representation of that pair, and asking the question of the hash would
     * only find duplicates the backfill had already agreed about — the whole
     * point is to find rows the *database* currently disagrees about.
     */
    private function duplicates(Connection $connection, bool $hasBrand): Collection
    {
        $columns = array_values(array_filter([
            $hasBrand ? 'brand_id' : null,
            'list_handle',
            'email_normalized',
        ]));

        return $connection->table(self::TABLE)
            ->select($columns)
            ->selectRaw('count(*) as occurrences')
            ->groupBy($columns)
            ->havingRaw('count(*) > 1')
            ->orderBy('list_handle')
            ->orderBy('email_normalized')
            ->get()
            ->map(function ($group) use ($connection, $columns) {
                $rows = $connection->table(self::TABLE)
                    ->where(collect($columns)->mapWithKeys(fn ($c) => [$c => $group->{$c}])->all())
                    ->orderBy('id')
                    ->get(['id', 'status', 'confirmed_at', 'created_at', 'source']);

                return (object) ['group' => $group, 'rows' => $rows, 'columns' => $columns];
            });
    }

    private function nullBrands(Connection $connection): int
    {
        return $connection->table(self::TABLE)->whereNull('brand_id')->count();
    }

    private function reportDuplicates(Collection $duplicates): void
    {
        if ($duplicates->isEmpty()) {
            $this->components->twoColumnDetail('<fg=gray>duplicate consent records</>', 'none');

            return;
        }

        $this->line('');
        $this->components->error(sprintf(
            '%d list/address pair(s) hold more than one subscription. These were accepted while no '
            .'unique was in place.',
            $duplicates->count(),
        ));

        foreach ($duplicates as $duplicate) {
            $this->line('');
            $this->line('  <fg=yellow>'.$duplicate->group->list_handle.'</> / <fg=yellow>'
                .$duplicate->group->email_normalized.'</>'
                // array_key_exists, not isset: a NULL brand_id is a state this
                // command exists to point at, and isset() would hide exactly it.
                .(array_key_exists('brand_id', (array) $duplicate->group)
                    ? ' (brand '.($duplicate->group->brand_id ?? 'NULL').')'
                    : ''));

            $this->table(
                ['id', 'status', 'confirmed_at', 'created_at', 'source'],
                $duplicate->rows->map(fn ($row) => [
                    $row->id,
                    $row->status,
                    $row->confirmed_at ?? '—',
                    $row->created_at ?? '—',
                    $row->source ?? '—',
                ])->all(),
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $present
     * @param  list<string>  $expectedColumns
     */
    private function repair(Builder $schema, ?array $present, string $expectedName, array $expectedColumns, bool $hasDuplicates): int
    {
        $this->line('');

        if ($hasDuplicates) {
            $this->components->error(
                'Refusing to rebuild the index: the rows above would be rejected by it. Which of them is '
                .'the consent record is a question about people, not about rows, so this command will not '
                .'answer it. Remove the ones that are not, then run --repair again.'
            );

            return self::FAILURE;
        }

        if ($present && $present['columns'] === $expectedColumns) {
            $this->components->info('The consent unique is already in place over ('.implode(', ', $expectedColumns).'); nothing to repair.');

            return self::SUCCESS;
        }

        if ($present) {
            $schema->table(self::TABLE, fn (Blueprint $table) => $table->dropUnique($present['name']));
        }

        $schema->table(self::TABLE, fn (Blueprint $table) => $table->unique($expectedColumns, $expectedName));

        $this->components->info(sprintf(
            'Rebuilt [%s] over (%s). Duplicate sign-ups on the same list are refused again.',
            $expectedName,
            implode(', ', $expectedColumns),
        ));

        $this->components->warn(
            'This restored the constraint only. Run `php artisan migrate` to finish the update itself — '
            .'the migration that was interrupted is still recorded as not run.'
        );

        return self::SUCCESS;
    }
}
