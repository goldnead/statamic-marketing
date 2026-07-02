<?php

namespace Goldnead\Marketing\Repositories\FlatFile;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Illuminate\Support\Collection;

class FlatFileMailingListRepository implements MailingListRepository
{
    public function __construct(protected YamlStore $store)
    {
    }

    public function all(): Collection
    {
        return $this->store->all('lists')
            ->map(fn (array $data) => MailingList::fromArray($data))
            ->sortBy(fn (MailingList $list) => mb_strtolower($list->name))
            ->values();
    }

    public function find(string $handle): ?MailingList
    {
        $data = $this->store->read('lists', $handle);

        return $data ? MailingList::fromArray($data) : null;
    }

    public function save(MailingList $list): MailingList
    {
        $this->store->write('lists', $list->handle, $list->toArray());

        return $list;
    }

    public function delete(string $handle): bool
    {
        return $this->store->delete('lists', $handle);
    }
}
