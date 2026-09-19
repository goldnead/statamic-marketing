<?php

namespace Goldnead\Marketing\Repositories\Eloquent;

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Models\EmailTemplateRecord;
use Illuminate\Support\Collection;

class EloquentEmailTemplateRepository implements EmailTemplateRepository
{
    use StampsTheBrandItself;

    public function all(): Collection
    {
        return EmailTemplateRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EmailTemplateRecord $record) => $this->toEntity($record));
    }

    public function find(string $handle): ?EmailTemplate
    {
        $record = EmailTemplateRecord::query()->where('handle', $handle)->first();

        return $record ? $this->toEntity($record) : null;
    }

    public function save(EmailTemplate $template): EmailTemplate
    {
        $this->speichereMitMarke(EmailTemplateRecord::class, $template->handle, [
            'name' => $template->name,
            'html' => $template->html,
        ]);

        return $template;
    }

    public function delete(string $handle): bool
    {
        return (bool) EmailTemplateRecord::query()->where('handle', $handle)->delete();
    }

    protected function toEntity(EmailTemplateRecord $record): EmailTemplate
    {
        return new EmailTemplate(
            handle: $record->handle,
            name: $record->name,
            html: (string) $record->html,
        );
    }
}
