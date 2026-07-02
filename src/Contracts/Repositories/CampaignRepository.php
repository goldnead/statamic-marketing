<?php

namespace Goldnead\Marketing\Contracts\Repositories;

use Goldnead\Marketing\Data\Campaign;
use Illuminate\Support\Collection;

interface CampaignRepository
{
    /** @return Collection<int, Campaign> */
    public function all(): Collection;

    public function find(string $handle): ?Campaign;

    public function save(Campaign $campaign): Campaign;

    public function delete(string $handle): bool;

    /** Campaigns with status "scheduled" whose scheduled_at is due. @return Collection<int, Campaign> */
    public function due(\DateTimeInterface $now): Collection;
}
