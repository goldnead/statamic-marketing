<?php

namespace Goldnead\Marketing\Repositories\Eloquent;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailingListRecord;
use Illuminate\Support\Collection;

class EloquentMailingListRepository implements MailingListRepository
{
    public function all(): Collection
    {
        return MailingListRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (MailingListRecord $record) => $this->toEntity($record));
    }

    public function find(string $handle): ?MailingList
    {
        $record = MailingListRecord::query()->where('handle', $handle)->first();

        return $record ? $this->toEntity($record) : null;
    }

    public function save(MailingList $list): MailingList
    {
        MailingListRecord::query()->updateOrCreate(
            ['handle' => $list->handle],
            [
                'name' => $list->name,
                'description' => $list->description,
                'double_opt_in' => $list->doubleOptIn,
            ],
        );

        return $list;
    }

    public function delete(string $handle): bool
    {
        return (bool) MailingListRecord::query()->where('handle', $handle)->delete();
    }

    protected function toEntity(MailingListRecord $record): MailingList
    {
        return new MailingList(
            handle: $record->handle,
            name: $record->name,
            description: $record->description,
            doubleOptIn: $record->double_opt_in,
        );
    }
}
