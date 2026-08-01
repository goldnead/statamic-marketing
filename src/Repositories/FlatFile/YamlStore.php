<?php

namespace Goldnead\Marketing\Repositories\FlatFile;

use Goldnead\Marketing\Exceptions\HandleNotUniqueAcrossBrands;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Minimal YAML-per-handle store under the configured flat path, one directory
 * per entity type (lists/, campaigns/, templates/). Definitions are
 * low-cardinality, so a plain directory scan is deliberate — no index layer.
 *
 * ## Brands live in the path, not in the file
 *
 * Under multi-brand every brand owns a directory and the type directories sit
 * inside it:
 *
 *     content/marketing/{brand}/lists/newsletter.yaml
 *
 * The alternative — a `brand:` key inside the file — was rejected. The handle
 * is the filename here, so a key would give every definition two identities
 * that can disagree, and reading one brand's lists would mean opening every
 * other brand's files to find out they are not yours. A missing or misspelt
 * key would then fall through to the default brand, which is a leak that looks
 * like a typo. With a directory the isolation is structural: a brand's read
 * never opens another brand's file, and being in the wrong place is visible in
 * `ls` and in a diff.
 *
 * ## The pre-brand layout keeps working
 *
 * Single-brand installs (the overwhelming majority, and every install before
 * 1.6) keep writing to `content/marketing/lists/…` exactly as before — no
 * directory, no move, nothing to do. Under multi-brand those files are read as
 * the default brand's, and only the default brand's, until
 * `marketing:migrate-flat-brands` moves them. An install that updates must
 * never open to empty lists.
 *
 * ## Handles are unique across brands
 *
 * A public subscribe form names its list and nothing else, so the brand is
 * derived from the list handle. That only holds while a handle belongs to
 * exactly one brand — so this store refuses a write that would give a second
 * brand a handle another one already holds.
 */
class YamlStore
{
    /**
     * The entity types kept here. Also the directory names that can never be
     * mistaken for a brand directory when the store enumerates brands.
     */
    public const TYPES = ['lists', 'campaigns', 'templates'];

    public function __construct(protected string $basePath) {}

    // -------------------------------------------------------------- paths

    /** The type directory inside a brand segment; '' is the pre-1.6 root. */
    public function directory(string $segment, string $type): string
    {
        $base = rtrim($this->basePath, '/');

        return $segment === '' ? $base.'/'.$type : $base.'/'.$segment.'/'.$type;
    }

    public function file(string $segment, string $type, string $handle): string
    {
        return $this->directory($segment, $type).'/'.$handle.'.yaml';
    }

    /** The path the current context writes to. */
    public function path(string $type, ?string $handle = null): string
    {
        $segment = $this->writeSegment();

        return $handle === null
            ? $this->directory($segment, $type)
            : $this->file($segment, $type, $handle);
    }

    // -------------------------------------------------------------- reads

    /** @return Collection<int, array> */
    public function all(string $type): Collection
    {
        $segments = $this->readSegments();

        if ($segments === null) {
            return collect();
        }

        $documents = [];

        foreach ($segments as $segment) {
            $dir = $this->directory($segment, $type);

            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*.yaml') ?: [] as $file) {
                $handle = basename($file, '.yaml');

                // The first segment wins: a migrated file shadows a copy that
                // an interrupted migration may have left in the old place.
                if (array_key_exists($handle, $documents)) {
                    continue;
                }

                if ($data = $this->parse($file, $handle)) {
                    $documents[$handle] = $data;
                }
            }
        }

        return collect(array_values($documents));
    }

    public function read(string $type, string $handle): ?array
    {
        foreach ($this->readSegments() ?? [] as $segment) {
            $file = $this->file($segment, $type, $handle);

            if (is_file($file)) {
                return $this->parse($file, $handle);
            }
        }

        return null;
    }

    // ------------------------------------------------------------- writes

    public function write(string $type, string $handle, array $data): void
    {
        $segment = $this->writeSegment();

        $this->guardHandleIsFree($type, $handle, $segment);

        $file = $this->file($segment, $type, $handle);

        // Never leave two files for one handle. An install that has not run
        // the migration yet keeps editing its definition where it already
        // lives, instead of gaining a second copy in the brand directory that
        // then shadows it.
        if (! is_file($file)) {
            foreach ($this->readSegments() ?? [] as $candidate) {
                $existing = $this->file($candidate, $type, $handle);

                if (is_file($existing)) {
                    $file = $existing;

                    break;
                }
            }
        }

        $dir = dirname($file);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // The handle is canonically the filename; the YAML body never carries it.
        unset($data['handle']);

        // Drop nulls so the YAML stays clean and diffs stay readable.
        $data = array_filter($data, fn ($value) => $value !== null);

        file_put_contents(
            $file,
            Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
        );
    }

    public function delete(string $type, string $handle): bool
    {
        $deleted = false;

        foreach ($this->readSegments() ?? [] as $segment) {
            $file = $this->file($segment, $type, $handle);

            if (is_file($file)) {
                unlink($file);
                $deleted = true;
            }
        }

        return $deleted;
    }

    // --------------------------------------------------------- cross-brand

    /**
     * Which brands hold a definition under this handle — across every brand,
     * ignoring the current context entirely.
     *
     * This is the flat driver's answer to "who owns this handle", and both the
     * uniqueness guard and the public-route brand derivation rest on it.
     *
     * @return array<int, string> brand handles
     */
    public function brandsWithHandle(string $type, string $handle): array
    {
        $brands = [];

        foreach ($this->segments() as $segment) {
            if (is_file($this->file($segment, $type, $handle))) {
                $brands[] = $this->brandOfSegment($segment);
            }
        }

        return array_values(array_unique($brands));
    }

    /** Every directory that can hold definitions, the pre-1.6 root first. */
    public function segments(): array
    {
        $segments = [''];

        foreach (glob(rtrim($this->basePath, '/').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);

            if (! in_array($name, self::TYPES, true)) {
                $segments[] = $name;
            }
        }

        return $segments;
    }

    /**
     * @throws HandleNotUniqueAcrossBrands
     */
    protected function guardHandleIsFree(string $type, string $handle, string $segment): void
    {
        $mine = $this->brandOfSegment($segment);

        foreach ($this->brandsWithHandle($type, $handle) as $brand) {
            if ($brand !== $mine) {
                throw HandleNotUniqueAcrossBrands::for($type, $handle, $brand);
            }
        }
    }

    // ----------------------------------------------------------- segments

    /**
     * The directories to read from, in priority order — or null when the
     * context must see nothing at all.
     *
     * Null mirrors the eloquent driver's fail-closed global scope: multi-brand
     * is on, no brand has been resolved, and handing back the default brand's
     * definitions instead would give whoever is asking data they never proved
     * they may see.
     */
    protected function readSegments(): ?array
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            // The untouched pre-1.6 layout first, the default brand's
            // directory behind it so an install that migrated and later turned
            // the flag off still finds its definitions.
            return array_values(array_unique(['', $this->defaultBrandHandle()]));
        }

        if (! $manager->hasCurrent()) {
            return $manager->failMode() === 'open' ? $this->segments() : null;
        }

        $current = $this->segmentFor($manager->current()->handle);

        // Only the default brand inherits the pre-1.6 files. They were written
        // before brands existed, so they belong to the brand every existing
        // row was backfilled onto — and to no other brand, ever.
        return $current === $this->segmentFor($manager->default()->handle)
            ? [$current, '']
            : [$current];
    }

    /** The directory new definitions are created in. */
    protected function writeSegment(): string
    {
        $manager = app('brand-context');

        // Single-brand: the layout stays exactly as it was before 1.6, so an
        // install that never enables multi-brand never sees a new directory.
        if (! $manager->multiBrandEnabled()) {
            return '';
        }

        // current() falls back to the default brand — which is precisely what
        // the eloquent driver stamps on a create in the same situation.
        return $this->segmentFor($manager->current()->handle);
    }

    protected function brandOfSegment(string $segment): string
    {
        return $segment === '' ? $this->defaultBrandHandle() : $segment;
    }

    protected function defaultBrandHandle(): string
    {
        $manager = app('brand-context');

        // Single-brand must not touch the database for this: the flat driver
        // is the one people run without ever caring that a brands table exists.
        return $this->segmentFor($manager->multiBrandEnabled()
            ? $manager->default()->handle
            : (string) config('brand-context.default_handle', 'default'));
    }

    /** A brand handle becomes a directory name, so it has to be usable as one. */
    protected function segmentFor(string $brandHandle): string
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $brandHandle)) {
            throw new InvalidArgumentException(
                "Brand handle [{$brandHandle}] cannot be used as a directory name."
            );
        }

        return $brandHandle;
    }

    // ------------------------------------------------------------ parsing

    protected function parse(string $file, string $handle): ?array
    {
        $data = Yaml::parse((string) file_get_contents($file));

        if (! is_array($data)) {
            return null;
        }

        // The handle is canonically the filename; the YAML body never wins.
        $data['handle'] = $handle;

        return $data;
    }
}
