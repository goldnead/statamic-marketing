<?php

namespace Goldnead\Marketing\Events;

use Goldnead\Marketing\Data\Campaign;
use Illuminate\Foundation\Events\Dispatchable;

abstract class CampaignEvent
{
    use Dispatchable;

    public function __construct(
        public Campaign $campaign,
        public array $metadata = [],
    ) {
    }

    public function toPayload(): array
    {
        return array_merge([
            'campaign' => $this->campaign->handle,
            'name' => $this->campaign->name,
            'subject' => $this->campaign->subject,
            'list' => $this->campaign->listHandle,
            'status' => $this->campaign->status,
        ], $this->metadata);
    }
}
