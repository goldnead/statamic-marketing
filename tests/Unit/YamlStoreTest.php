<?php

use Goldnead\Marketing\Repositories\FlatFile\YamlStore;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/marketing-yamlstore-'.uniqid();
    $this->store = new YamlStore($this->path);
});

afterEach(function (): void {
    foreach (glob($this->path.'/*/*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($this->path.'/*') ?: [] as $dir) {
        @rmdir($dir);
    }
    @rmdir($this->path);
});

it('round-trips documents and canonicalizes the handle from the filename', function (): void {
    $this->store->write('lists', 'newsletter', [
        'handle' => 'SHOULD_BE_IGNORED',
        'name' => 'Newsletter',
        'double_opt_in' => true,
        'nothing' => null,
    ]);

    $data = $this->store->read('lists', 'newsletter');

    expect($data['handle'])->toBe('newsletter')
        ->and($data['name'])->toBe('Newsletter')
        ->and($data)->not->toHaveKey('nothing');

    expect($this->store->all('lists'))->toHaveCount(1);

    $this->store->delete('lists', 'newsletter');

    expect($this->store->read('lists', 'newsletter'))->toBeNull()
        ->and($this->store->all('lists'))->toHaveCount(0);
});

it('preserves multi-line HTML content', function (): void {
    $html = "<html>\n<body>\n  {{ content }}\n</body>\n</html>";

    $this->store->write('templates', 'branded', ['name' => 'Branded', 'html' => $html]);

    expect($this->store->read('templates', 'branded')['html'])->toBe($html);
});

it('returns an empty collection for unknown types', function (): void {
    expect($this->store->all('nope'))->toHaveCount(0);
});
