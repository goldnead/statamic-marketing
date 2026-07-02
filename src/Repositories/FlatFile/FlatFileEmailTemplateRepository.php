<?php

namespace Goldnead\Marketing\Repositories\FlatFile;

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Illuminate\Support\Collection;

class FlatFileEmailTemplateRepository implements EmailTemplateRepository
{
    public function __construct(protected YamlStore $store)
    {
    }

    public function all(): Collection
    {
        return $this->store->all('templates')
            ->map(fn (array $data) => EmailTemplate::fromArray($data))
            ->sortBy(fn (EmailTemplate $template) => mb_strtolower($template->name))
            ->values();
    }

    public function find(string $handle): ?EmailTemplate
    {
        $data = $this->store->read('templates', $handle);

        return $data ? EmailTemplate::fromArray($data) : null;
    }

    public function save(EmailTemplate $template): EmailTemplate
    {
        $this->store->write('templates', $template->handle, $template->toArray());

        return $template;
    }

    public function delete(string $handle): bool
    {
        return $this->store->delete('templates', $handle);
    }
}
