<?php

namespace Goldnead\Marketing\Events;

use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\PreferenceLink;
use Illuminate\Foundation\Events\Dispatchable;

abstract class SubscriptionEvent
{
    use Dispatchable;

    public function __construct(
        public Subscription $subscription,
        public array $metadata = [],
    ) {}

    /**
     * Normalized payload for automation triggers and outbound webhooks.
     *
     * The keys are a contract. `MarketingSubscribed` and `MarketingUnsubscribed`
     * feed a queued Brevo sync and whatever else an install has wired to them,
     * so `unsubscribe_url` keeps meaning what it has always meant: marketing's
     * own endpoint, which unsubscribes whether a machine POSTs it or a person
     * opens it. `preferences_url` is added beside it for the human-facing page,
     * which moves to the preference-centre addon when that is installed. Both
     * come from the one resolver; see Support\PreferenceLink.
     */
    public function toPayload(): array
    {
        $links = app(PreferenceLink::class);

        return array_merge([
            'subscription_uuid' => $this->subscription->uuid,
            'list' => $this->subscription->list_handle,
            'email' => $this->subscription->email,
            'first_name' => $this->subscription->first_name,
            'last_name' => $this->subscription->last_name,
            'status' => $this->subscription->status,
            'contact_uuid' => $this->subscription->contact_uuid,
            'source' => $this->subscription->source,
            'unsubscribe_url' => $this->subscription->token
                ? $links->oneClick($this->subscription->token)
                : null,
            'preferences_url' => $this->subscription->token
                ? $links->manage($this->subscription->token)
                : null,
        ], $this->metadata);
    }
}
