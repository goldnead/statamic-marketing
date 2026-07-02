<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Definition entities (mailing lists, campaigns, email templates) can be
    | stored as flat YAML files — the Statamic way, ideal for version control —
    | or in the database. Runtime data (subscriptions, sent messages, open and
    | click events) always lives in the database regardless of this setting.
    |
    | Supported drivers: "flat", "eloquent".
    |
    */

    'storage' => [
        'driver' => env('MARKETING_DRIVER', 'flat'),

        'flat' => [
            'path' => env('MARKETING_FLAT_PATH', base_path('content/marketing')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    |
    | Which Laravel mailer to send campaigns through (null = default mailer),
    | the queue campaign jobs run on, how many recipients are snapshotted per
    | chunk, and an optional per-minute throttle (0 = unthrottled) to respect
    | ESP rate limits.
    |
    */

    'sending' => [
        'mailer' => env('MARKETING_MAILER'),
        'queue' => env('MARKETING_QUEUE', 'default'),
        'chunk' => 200,
        'messages_per_minute' => (int) env('MARKETING_PER_MINUTE', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default sender
    |--------------------------------------------------------------------------
    |
    | Fallback From header for campaigns and transactional double-opt-in mail
    | when a campaign doesn't define its own. Defaults to the application's
    | global mail.from settings.
    |
    */

    'from' => [
        'name' => env('MARKETING_FROM_NAME'),
        'email' => env('MARKETING_FROM_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    |
    | double_opt_in is the default for new lists (each list can override it).
    | The honeypot field is rendered by the {{ marketing:subscribe }} tag and
    | checked by the public subscribe endpoint: bots that fill it get a fake
    | success response and no subscription.
    |
    */

    'subscriptions' => [
        'double_opt_in' => true,
        'honeypot' => 'website',
    ],

    /*
    |--------------------------------------------------------------------------
    | Unsubscribes
    |--------------------------------------------------------------------------
    |
    | Unsubscribing always ends the list subscription. With global_opt_out
    | enabled it additionally flags the LeadHub contact as do-not-contact,
    | opting them out of every list and CRM mailing.
    |
    */

    'unsubscribe' => [
        'global_opt_out' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */

    'tracking' => [
        'opens' => true,
        'clicks' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public routes
    |--------------------------------------------------------------------------
    |
    | Prefix for the public endpoints (subscribe, confirm, unsubscribe, open
    | pixel, click redirects). The bang prefix mirrors Statamic's own action
    | routes and webhook-manager's inbound endpoints.
    |
    */

    'routes' => [
        'prefix' => env('MARKETING_ROUTE_PREFIX', '!/marketing'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LeadHub
    |--------------------------------------------------------------------------
    |
    | Subscribers are LeadHub contacts. tag_subscribers attaches a
    | "{tag_prefix}{list handle}" tag to the contact on confirmation so lists
    | are visible (and segmentable) inside LeadHub as well.
    |
    */

    'leadhub' => [
        'tag_subscribers' => true,
        'tag_prefix' => 'list:',
        'hard_bounce_opt_out' => true,
        'complaint_opt_out' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sibling addon integrations
    |--------------------------------------------------------------------------
    |
    | Both are detected at runtime and are safe to leave enabled when the
    | addons aren't installed.
    |
    */

    'integrations' => [
        'automations' => true,
        'webhook_manager' => true,
    ],

];
