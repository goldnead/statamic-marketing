<?php

namespace Goldnead\Marketing\Repositories\FlatFile;

use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

/**
 * Minimal YAML-per-handle store under the configured flat path, one directory
 * per entity type (lists/, campaigns/, templates/). Definitions are
 * low-cardinality, so a plain directory scan is deliberate — no index layer.
 */
class YamlStore
{
    public function __construct(protected string $basePath)
    {
    }

    public function path(string $type, ?string $handle = null): string
    {
        $dir = rtrim($this->basePath, '/').'/'.$type;

        return $handle ? $dir.'/'.$handle.'.yaml' : $dir;
    }

    /** @return Collection<int, array> */
    public function all(string $type): Collection
    {
        $dir = $this->path($type);

        if (! is_dir($dir)) {
            return collect();
        }

        return collect(glob($dir.'/*.yaml') ?: [])
            ->map(fn (string $file) => $this->read($type, basename($file, '.yaml')))
            ->filter()
            ->values();
    }

    public function read(string $type, string $handle): ?array
    {
        $file = $this->path($type, $handle);

        if (! is_file($file)) {
            return null;
        }

        $data = Yaml::parse((string) file_get_contents($file));

        if (! is_array($data)) {
            return null;
        }

        // The handle is canonically the filename; the YAML body never wins.
        $data['handle'] = $handle;

        return $data;
    }

    public function write(string $type, string $handle, array $data): void
    {
        $dir = $this->path($type);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        unset($data['handle']);

        // Drop nulls so the YAML stays clean and diffs stay readable.
        $data = array_filter($data, fn ($value) => $value !== null);

        file_put_contents(
            $this->path($type, $handle),
            Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)
        );
    }

    public function delete(string $type, string $handle): bool
    {
        $file = $this->path($type, $handle);

        return is_file($file) ? unlink($file) : false;
    }
}
