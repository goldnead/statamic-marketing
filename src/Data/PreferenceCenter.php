<?php

namespace Goldnead\Marketing\Data;

use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Everything the preference page renders, resolved from one unsubscribe token.
 *
 * **What the token discloses.** The page shows the address the token belongs
 * to and every list of this brand the address is on. That is a deliberate
 * decision, not an oversight: the token was delivered to that mailbox and is
 * repeated in every mail sent from this brand, so whoever holds it can already
 * read the mail the memberships are visible in. It buys nothing an attacker
 * did not have, and without it the page cannot do its job. What the token must
 * not do is widen: it never reaches past its own brand, and it can end consent
 * but never lift a block (see `$contactSuppressed`).
 */
class PreferenceCenter
{
    public function __construct(
        /** The subscription the token addresses. */
        public Subscription $subscription,
        /** @var Collection<int, ListPreference> every list of this brand */
        public Collection $rows,
        /**
         * The LeadHub contact carries `do_not_contact` — an opt-out recorded by
         * hand in the CRM, or a global unsubscribe. Every row is then
         * un-switchable, whatever its own state.
         *
         * This is one of the two sources a row's `suppressed` is built from,
         * not the whole answer: since 1.8.0 a provider bounce or complaint is
         * recorded in the suppression table and never reaches this flag. Read
         * `ListPreference::$suppressed` to ask whether a row is blocked.
         */
        public bool $contactSuppressed = false,
    ) {}

    public function email(): string
    {
        return (string) $this->subscription->email;
    }

    public function token(): string
    {
        return (string) $this->subscription->token;
    }

    /** @return Collection<int, ListPreference> */
    public function activeRows(): Collection
    {
        return $this->rows->filter(fn (ListPreference $row) => $row->active)->values();
    }

    public function hasAnythingActive(): bool
    {
        return $this->activeRows()->isNotEmpty();
    }
}
