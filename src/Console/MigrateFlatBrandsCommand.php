<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Repositories\FlatFile\YamlStore;
use Illuminate\Console\Command;

/**
 * Moves the pre-1.6 flat definitions into a brand directory.
 *
 * Before 1.6 the flat driver kept lists, campaigns and templates directly
 * under `content/marketing/{type}/`, where nothing carried a brand. Those
 * files belong to the brand every existing database row was backfilled onto —
 * the default one — and the store reads them as such, so nothing has to be
 * moved for the install to keep working. This command is for the step after
 * that: once a second brand exists, every brand's definitions want their own
 * directory, and the pre-1.6 files have to say out loud whose they are.
 *
 * Idempotent by construction: it only ever moves files that are still in the
 * old place, so a second run finds nothing to do. It refuses rather than
 * overwrites, and `--dry-run` shows the exact moves without touching a thing.
 */
class MigrateFlatBrandsCommand extends Command
{
    protected $signature = 'marketing:migrate-flat-brands
        {--brand= : The brand the existing definitions belong to (default: the default brand)}
        {--dry-run : Show what would move, change nothing}';

    protected $description = 'Move pre-1.6 flat marketing definitions into a brand directory';

    public function handle(YamlStore $store): int
    {
        if (config('marketing.storage.driver', 'flat') === 'eloquent') {
            $this->components->warn('The eloquent driver is configured; there are no flat files to move.');

            return self::SUCCESS;
        }

        $brand = $this->targetBrand();

        if (! $brand) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(sprintf(
            '%s pre-1.6 definitions to brand [%s] under %s',
            $dryRun ? 'Would move' : 'Moving',
            $brand,
            config('marketing.storage.flat.path'),
        ));

        $moved = 0;
        $refused = 0;
        $rows = [];

        foreach (YamlStore::TYPES as $type) {
            foreach ($this->legacyFiles($store, $type) as $handle => $source) {
                $target = $store->file($brand, $type, $handle);

                if ($reason = $this->refusal($store, $type, $handle, $brand, $target)) {
                    $rows[] = [$type, $handle, 'refused', $reason];
                    $refused++;

                    continue;
                }

                $rows[] = [$type, $handle, $dryRun ? 'would move' : 'moved', $this->relative($target)];

                if (! $dryRun) {
                    $this->move($source, $target);
                }

                $moved++;
            }
        }

        if ($rows === []) {
            $this->components->info('Nothing to move — no definitions left in the pre-1.6 layout.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'Handle', 'Action', 'Detail'], $rows);

        $this->components->twoColumnDetail(
            $dryRun ? 'Would move' : 'Moved',
            (string) $moved,
        );

        if ($refused) {
            $this->components->twoColumnDetail('Refused', (string) $refused);
            $this->components->error(
                'Some definitions were left where they are. Nothing was deleted or overwritten; '
                .'resolve the conflicts above and run the command again.'
            );

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->components->info('Dry run — nothing on disk was touched.');
        }

        return self::SUCCESS;
    }

    /** The brand handle to move into, or null when it cannot be determined. */
    protected function targetBrand(): ?string
    {
        if ($handle = $this->option('brand')) {
            if (! Brand::query()->where('handle', $handle)->exists()) {
                $this->components->error("Unknown brand [{$handle}].");

                return null;
            }

            return (string) $handle;
        }

        $default = Brand::default();

        if (! $default) {
            $this->components->error(
                'No default brand found. Run the goldnead/statamic-brand-context migrations, '
                .'or name a brand with --brand.'
            );

            return null;
        }

        return (string) $default->handle;
    }

    /** @return array<string, string> handle => absolute path, pre-1.6 layout only */
    protected function legacyFiles(YamlStore $store, string $type): array
    {
        $files = [];

        foreach (glob($store->directory('', $type).'/*.yaml') ?: [] as $file) {
            $files[basename($file, '.yaml')] = $file;
        }

        ksort($files);

        return $files;
    }

    /** Why this file must stay where it is, or null when it may move. */
    protected function refusal(
        YamlStore $store,
        string $type,
        string $handle,
        string $brand,
        string $target,
    ): ?string {
        if (is_file($target)) {
            return 'target exists: '.$this->relative($target);
        }

        // Handles are unique across brands — that is what lets the public
        // subscribe endpoint derive a brand from a list handle at all. Moving
        // a file into a brand while another brand already holds the handle
        // would break exactly that, so it does not happen. Only real brand
        // directories are claims here: the pre-1.6 file is the one being
        // moved, so it cannot be its own obstacle.
        foreach ($store->segments() as $segment) {
            if ($segment === '' || $segment === $brand) {
                continue;
            }

            if (is_file($store->file($segment, $type, $handle))) {
                return "handle already belongs to brand [{$segment}]";
            }
        }

        return null;
    }

    protected function move(string $source, string $target): void
    {
        $dir = dirname($target);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        rename($source, $target);
    }

    protected function relative(string $path): string
    {
        $base = rtrim((string) config('marketing.storage.flat.path'), '/');

        return str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
    }
}
