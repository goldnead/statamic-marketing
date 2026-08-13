<?php

return [
    /*
     * The page before the confirmation. It exists because opening a link is
     * not yet a decision: link scanners on mail gateways and preview features
     * fetch every URL in an incoming message. The button is the consent.
     */
    'confirm_title' => 'Confirm your subscription',
    'confirm_body' => 'Please confirm with one click that you would like to subscribe to ":list".',
    'confirm_button' => 'Confirm subscription',

    'confirm_expired_title' => 'This link has expired',
    'confirm_expired_body' => 'This confirmation link is no longer valid. Just sign up again and we will send you a new one.',

    'confirmed_title' => 'Subscription confirmed',
    'confirmed_body' => 'You are now subscribed to ":list". Welcome!',
    /*
     * The line in the text part of a campaign. It lives in the mailable's view
     * rather than in the renderer, so it arrives in the recipient's language
     * instead of the application's.
     */
    'unsubscribe_text' => 'Unsubscribe',

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
