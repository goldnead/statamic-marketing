<?php

namespace Goldnead\Marketing\Events;

use Goldnead\Marketing\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

abstract class SubscriptionEvent
{
    use Dispatchable;

    public function __construct(
        public Subscription $subscription,
        public array $metadata = [],
    ) {
    }

    /** Normalized payload for automation triggers and outbound webhooks. */
    public function toPayload(): array
    {
        return array_merge([
            'subscription_uuid' => $this->subscription->uuid,
            'list' => $this->subscription->list_handle,
            'email' => $this->subscription->email,
            'first_name' => $this->subscription->first_name,
            'last_name' => $this->subscription->last_name,
            'status' => $this->subscription->status,
            'contact_uuid' => $this->subscription->contact_uuid,
            'source' => $this->subscription->source,
        ], $this->metadata);
    }
}
