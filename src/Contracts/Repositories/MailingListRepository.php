<?php

namespace Goldnead\Marketing\Contracts\Repositories;

use Goldnead\Marketing\Data\MailingList;
use Illuminate\Support\Collection;

interface MailingListRepository
{
    /** @return Collection<int, MailingList> */
    public function all(): Collection;

    public function find(string $handle): ?MailingList;

    public function save(MailingList $list): MailingList;

    public function delete(string $handle): bool;
}
