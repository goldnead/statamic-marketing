<?php

namespace Goldnead\Marketing\Data;

use Goldnead\Marketing\Models\Subscription;

/**
 * One row of the preference centre: a mailing list of the current brand,
 * together with what this person's consent for it currently is.
 *
 * A row exists for every list of the brand, including lists the person has
 * never been on — those simply carry no subscription. That is what makes the
 * page a preference centre rather than a list of things to leave.
 */
class ListPreference
{
    public function __construct(
        public MailingList $list,
        public ?Subscription $subscription,
        public bool $active,
        public bool $suppressed,
        /**
         * Why the row cannot be switched on. Never rendered: the page says
         * only that sending is blocked, because the token that opened it is
         * in an inbox and proves nothing about who is holding it. The value
         * is here so the refusal can be tested and logged by its real cause.
         */
        public ?string $suppressionReason = null,
        /** The list the token itself belongs to, highlighted on the page. */
        public bool $current = false,
    ) {
    }

    public function handle(): string
    {
        return $this->list->handle;
    }
}
