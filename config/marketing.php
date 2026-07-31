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
    | Multi-brand and the flat driver: every brand owns a directory under the
    | flat path, with the type directories inside it —
    | content/marketing/{brand}/lists/newsletter.yaml. Single-brand installs
    | keep the plain content/marketing/lists/… layout and need to change
    | nothing. Files still in that layout are read as the default brand's until
    | `php artisan marketing:migrate-flat-brands` moves them.
    |
    | List handles are unique across ALL brands in both drivers: the public
    | subscribe endpoint derives the brand from the list handle the form names,
    | which only works while a handle has exactly one owner.
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
    | Delivery — surviving a provider that rewrites the links
    |--------------------------------------------------------------------------
    |
    | Most sending platforms count clicks by rewriting every `href` in the HTML
    | part onto a counter of their own, then forwarding the reader with an extra
    | parameter attached. The click-tracking redirect this addon signs does not
    | survive that: Laravel signs the whole query string, so one appended
    | parameter is a 403 — measured, not assumed. Brevo's counter turned a
    | working link into `403 …/c/{uuid}?_se=…&url=…&signature=…`, and the click
    | went uncounted with it. Confirmation and unsubscribe links are untouched;
    | their token is in the path and nothing about them is signed.
    |
    | Two answers, and a host wants both.
    |
    |
    | `mail_headers` — stop the rewriting at the source.
    |
    | Most providers take a per-message header that turns click tracking off for
    | that one message. Anything listed here is added verbatim to the outgoing
    | campaign and double-opt-in message, so this addon needs no provider of its
    | own. Empty by default — an addon that guessed your provider and changed
    | how it behaves would be worse than one that asks. Note that this addon
    | counts clicks itself, so switching the provider's own counter off costs
    | nothing but the provider's dashboard.
    |
    | Verified against each vendor's own documentation, July 2026:
    |
    |   Mailgun        'X-Mailgun-Track-Clicks' => 'no'
    |   Postmark       'X-PM-TrackLinks' => 'None'
    |   SparkPost      'X-MSYS-API' => '{"options":{"click_tracking":false}}'
    |   SendGrid       'X-SMTPAPI' => '{"filters":{"clicktrack":{"settings":{"enable":0}}}}'
    |   Mailjet        'X-Mailjet-TrackClick' => '0'
    |   Mandrill       'X-MC-Track' => 'opens'   (an allow-list: anything not
    |                                             named is switched off)
    |   Elastic Email  'trackclicks' => 'false'
    |
    |   Amazon SES     no header. SES rewrites links only when the configuration
    |                  set named in `X-SES-CONFIGURATION-SET` publishes click
    |                  events, so sending without that header is already the off
    |                  position. Per link: `<a ses:no-track href="…">`.
    |   Resend         no header; tracking is off by default, per domain.
    |   Brevo          no header, and none is coming. `X-Mailin-custom`,
    |                  `X-Sib-Sandbox` and `X-SIB-API` are the documented ones
    |                  and none of them touches tracking. On Brevo the ignore
    |                  list below is not defence in depth — it is the only thing
    |                  that works.
    |
    |
    | `ignored_query_parameters` — survive the rewriting when it happens.
    |
    | These names are left out of the signature check. That is a real cost and
    | it is bounded, but the bound is tighter here than for a magic link: this
    | route carries its destination in the query, as `?url=https://…`. A `url`
    | on this list would be an open redirect on your own domain, not merely a
    | weaker signature. `Support\TrackingParameters` therefore refuses to ignore
    | `url`, `expires` or `signature` however this list is edited. Every name
    | below is a parameter a mail provider adds to somebody else's URL. Names
    | that only an ad network or a referrer adds — `gclid`, `fbclid` — are
    | deliberately absent: they do not appear on the path from a mail to this
    | route, and a list that grows by association is how one ends up ignoring
    | the wrong thing.
    |
    */

    'delivery' => [

        'mail_headers' => [
            // 'X-Mailgun-Track-Clicks' => 'no',
        ],

        'ignored_query_parameters' => [
            // Brevo (Sendinblue): the recipient address, base64, appended by the
            // click redirector. This is the one that was measured.
            '_se',

            // Brevo's "Google Analytics tagging", and the same switch in
            // Mailchimp, Mailjet, Postmark and Klaviyo: five parameters appended
            // to every link in the message.
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',

            // Mailchimp: campaign id and recipient id.
            'mc_cid',
            'mc_eid',

            // HubSpot: the encrypted recipient token and the message id.
            '_hsenc',
            '_hsmi',

            // Marketo (Adobe): the recipient token.
            'mkt_tok',
        ],

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
