<?php

return [
    'confirmed_title' => 'Subscription confirmed',
    'confirmed_body' => 'You are now subscribed to ":list". Welcome!',
    'unsubscribed_title' => 'Unsubscribed',
    'unsubscribed_body' => 'You have been removed from ":list". You will not receive further emails from this list.',

    /*
     * Only shown where a preference centre is installed. Marketing itself no
     * longer renders a preference page — see Support/PreferenceLink — so the
     * sentence promises nothing this install cannot deliver.
     */
    'unsubscribed_manage' => 'Other lists continue independently of this one.',
    'unsubscribed_manage_link' => 'Manage your email preferences',

    /*
     * The web archive. `archive_neutral_name` stands in for {{ first_name }}
     * and {{ name }} on a page that has no recipient — override it in
     * `marketing.archive.neutral_name` where the newsletter's own voice needs
     * a different word.
     */
    'archive_neutral_name' => 'there',
    'archive_empty' => 'No issues have been published here yet.',
    'archive_feed_link' => 'RSS feed',
];
