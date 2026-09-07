<?php

/*
 * The settings screen at /cp/brand-settings.
 *
 * Key-for-key identical with resources/lang/de/settings.php. Field keys are the
 * config path with the dots replaced (`from.email` -> `from_email`), because a
 * dot in a lang key is a path separator to the translator.
 *
 * Descriptions say what happens when the value changes, not what the field is
 * called.
 */

return [

    'groups' => [

        'sender' => [
            'title' => 'Sender and footer',
            'description' => 'Who the mail comes from, and the postal address printed under it. These are per brand: on a host with several brands each gets its own. Once set, they outrank the matching variable in .env.',
        ],

        'sending' => [
            'title' => 'Sending',
            'description' => 'Pace and delivery window. Changes take effect from the next send onwards, not retroactively on a campaign already going out. Extra mail headers (marketing.delivery.mail_headers) stay in config/marketing.php: it is a map with free-form keys, and there is no field for it here.',
        ],

        'subscriptions' => [
            'title' => 'Sign-up',
            'description' => 'How somebody gets onto a list, and how often the system writes to them on the way. The double opt-in value is only the default: every list can overrule it on its own page.',
        ],

        'after_sending' => [
            'title' => 'After sending',
            'description' => 'What is counted, and what an unsubscribe pulls with it. Switching counting off deletes nothing already counted: the existing figures stay in the reports, nothing new is added.',
        ],

        'archive' => [
            'title' => 'Web archive',
            'description' => 'The public web version of a campaign. Whether the archive runs at all, and under which path, stays in config/marketing.php: both are read while the routes are built, before a setting from here can exist.',
        ],

        'leadhub' => [
            'title' => 'LeadHub',
            'description' => 'What marketing changes on the contacts in LeadHub. Nothing happens retroactively: a change applies from the next sign-up, bounce or complaint onwards.',
        ],

    ],

    'fields' => [

        'from_name' => [
            'label' => 'Sender name',
            'description' => 'The name recipients see in their inbox when a campaign sets none of its own. Leave empty and the brand’s own sender identity applies.',
        ],
        'from_email' => [
            'label' => 'Sender address',
            'description' => 'The address campaigns are sent from when they set none of their own. An address whose domain is not authorised lands in spam or is rejected outright.',
        ],
        'footer_postal_line' => [
            'label' => 'Postal address',
            'description' => 'The postal address German law (§ 5 DDG) requires under every marketing mail. Left empty, the renderer appends nothing and the mail goes out without it. Several lines are allowed and are kept as line breaks. Where this host binds a PostalLineResolver of its own, this value is never read and the field has no effect.',
        ],

        'sending_chunk' => [
            'label' => 'Recipients per block',
            'description' => 'How many subscribers the start of a campaign loads at once. Higher means fewer database queries and more memory per worker.',
        ],
        'sending_messages_per_minute' => [
            'label' => 'Mails per minute',
            'description' => 'The throttle against the sending provider’s rate limit. 0 sends as fast as the workers can. Set too high, the provider rejects mails and the recipients behind them get nothing.',
        ],
        'sending_claim_lease_minutes' => [
            'label' => 'Message lease',
            'description' => 'How long a worker keeps a message to itself before it counts as crashed and another one takes over. Set too short, a slow mail is sent twice.',
        ],
        'sending_window_from' => [
            'label' => 'Deliver from',
            'description' => 'Full hour in the recipient’s own timezone from which delivery is allowed. Leave empty for round the clock. A mail falling due outside the window is deferred, not discarded.',
        ],
        'sending_window_to' => [
            'label' => 'Deliver until',
            'description' => 'Full hour from which delivery stops. Leave empty together with the start value, or the window has no effect at all.',
        ],
        'sending_window_timezone' => [
            'label' => 'Window timezone',
            'description' => 'Applies to recipients whose own timezone is unknown, for example Europe/Berlin. A value PHP does not recognise is passed over silently and the application timezone applies.',
        ],

        'subscriptions_double_opt_in' => [
            'label' => 'Double opt-in by default',
            'description' => 'Whether newly created lists require a confirmation mail. Existing lists keep whatever their own page says.',
        ],
        'subscriptions_honeypot' => [
            'label' => 'Honeypot field name',
            'description' => 'The invisible form field only a bot fills in. Change it once bots have learned the current name. Your own forms then have to carry the new name, or every sign-up is rejected as a bot.',
        ],
        'subscriptions_confirmation_ttl_hours' => [
            'label' => 'Confirmation link lifetime',
            'description' => 'Hours a confirmation link keeps working. 0 lets it never expire. Whoever clicks too late sees a page asking them to sign up again.',
        ],
        'subscriptions_confirm_requires_post' => [
            'label' => 'Confirmation needs a click on the page',
            'description' => 'On, the link first shows a page with a button rather than confirming outright. Off, any mailbox link scanner confirms the sign-up without a human ever having seen it.',
        ],
        'subscriptions_confirmation_throttle_enabled' => [
            'label' => 'Throttle confirmation mails',
            'description' => 'Off, anybody can trigger any number of confirmation mails at a stranger’s address by resubmitting the form.',
        ],
        'subscriptions_confirmation_throttle_per_list' => [
            'label' => 'Confirmation mails per list',
            'description' => 'How many confirmation mails one address gets per list inside the window below. Further sign-ups are still accepted, they just no longer send a mail.',
        ],
        'subscriptions_confirmation_throttle_per_list_window_minutes' => [
            'label' => 'Window per list',
            'description' => 'Minutes the per-list count runs over.',
        ],
        'subscriptions_confirmation_throttle_per_mailbox' => [
            'label' => 'Confirmation mails per mailbox',
            'description' => 'The ceiling across all lists together. It is the limit that bites when somebody signs one address up to ten lists at once.',
        ],
        'subscriptions_confirmation_throttle_per_mailbox_window_minutes' => [
            'label' => 'Window per mailbox',
            'description' => 'Minutes the per-mailbox count runs over.',
        ],

        'unsubscribe_global_opt_out' => [
            'label' => 'Unsubscribe blocks the whole contact',
            'description' => 'On, unsubscribing from one list marks the LeadHub contact "do not contact" and they receive nothing from any other list either. Off, the unsubscribe affects that one list only.',
        ],
        'tracking_opens' => [
            'label' => 'Count opens',
            'description' => 'On, every sent mail carries a counting pixel. Off, the open rate and the "when it was read" curve stay at the figures gathered so far.',
        ],
        'tracking_clicks' => [
            'label' => 'Count clicks',
            'description' => 'On, links in the mail are rewritten to a signed redirect. Off, mails show the target address unchanged and no new clicks are added.',
        ],
        'frequency_cap_enabled' => [
            'label' => 'Frequency cap',
            'description' => 'Limits how many marketing mails one address receives in a period, across all lists. A capped mail is deferred, not discarded outright.',
        ],
        'frequency_cap_max' => [
            'label' => 'Mails per period',
            'description' => 'How many mails one address may receive inside the window below.',
        ],
        'frequency_cap_window_hours' => [
            'label' => 'Length of the period',
            'description' => 'Hours the cap counts over. 168 is one week.',
        ],
        'frequency_cap_defer_retry_after_minutes' => [
            'label' => 'Retry after',
            'description' => 'Minutes after which a deferred mail is checked again.',
        ],
        'frequency_cap_defer_max_deferrals' => [
            'label' => 'Deferrals before giving up',
            'description' => 'How often a mail may be deferred before it is left as "capped" for good. 0 discards it the first time instead of trying again.',
        ],

        'archive_title' => [
            'label' => 'Archive title',
            'description' => 'The heading above the public campaign overview. Leave empty to use the site name.',
        ],
        'archive_neutral_name' => [
            'label' => 'Greeting in the web version',
            'description' => 'The word the web version puts where the mail would put a first name. Set the word your newsletter would actually use, or the web version reads like a placeholder.',
        ],
        'archive_feed_limit' => [
            'label' => 'Entries in the feed',
            'description' => 'How many campaigns the archive’s RSS feed serves.',
        ],

        'leadhub_tag_subscribers' => [
            'label' => 'Tag subscribers in LeadHub',
            'description' => 'On, a contact gains a tag for the list on confirming and loses it on unsubscribing. Off, tags already set stay where they are, only no new ones are added.',
        ],
        'leadhub_tag_prefix' => [
            'label' => 'Tag prefix',
            'description' => 'Goes in front of the list handle, for example "list:newsletter". After a change the old tags no longer match the new prefix and are no longer removed on unsubscribe.',
        ],
        'leadhub_hard_bounce_opt_out' => [
            'label' => 'A hard bounce blocks the contact',
            'description' => 'On, a permanently undeliverable address marks the LeadHub contact "do not contact". Off, the bounce is only recorded in the report.',
        ],
        'leadhub_complaint_opt_out' => [
            'label' => 'A spam complaint blocks the contact',
            'description' => 'On, a complaint at the provider blocks the LeadHub contact. Switching this off is not advisable: continuing to write to somebody who complained costs the sending domain its deliverability.',
        ],

    ],

];
