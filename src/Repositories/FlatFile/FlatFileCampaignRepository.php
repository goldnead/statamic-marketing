<?php

namespace Goldnead\Marketing\Repositories\FlatFile;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Illuminate\Support\Collection;

class FlatFileCampaignRepository implements CampaignRepository
{
    public function __construct(protected YamlStore $store)
    {
    }

    public function all(): Collection
    {
        return $this->store->all('campaigns')
            ->map(fn (array $data) => Campaign::fromArray($data))
            ->sortByDesc(fn (Campaign $campaign) => $campaign->sentAt?->timestamp
                ?? $campaign->scheduledAt?->timestamp
                ?? PHP_INT_MAX)
            ->values();
    }

    public function find(string $handle): ?Campaign
    {
        $data = $this->store->read('campaigns', $handle);

        return $data ? Campaign::fromArray($data) : null;
    }

    public function save(Campaign $campaign): Campaign
    {
        $this->store->write('campaigns', $campaign->handle, $campaign->toArray());

        return $campaign;
    }

    public function delete(string $handle): bool
    {
        return $this->store->delete('campaigns', $handle);
    }

    public function due(\DateTimeInterface $now): Collection
    {
        return $this->all()
            ->filter(fn (Campaign $campaign) => $campaign->isScheduled()
                && $campaign->scheduledAt
                && $campaign->scheduledAt->lessThanOrEqualTo($now))
            ->values();
    }
}
