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
    | `mailer` is the FALLBACK, not the last word. A brand that names its own
    | transport in `brands.settings.mail.mailer` sends through that one, because
    | a relay verifies sending domains per account and the account has to match
    | the From. Brands that name none — every brand in a single-brand install —
    | use the value here, unchanged. See README, "Every brand sends as itself".
    |
    */

    'sending' => [
        'mailer' => env('MARKETING_MAILER'),
        'queue' => env('MARKETING_QUEUE', 'default'),
        'chunk' => 200,
        'messages_per_minute' => (int) env('MARKETING_PER_MINUTE', 0),

        /*
         * How long a worker may hold a message before it is assumed dead.
         *
         * The claim on a message is what stops one mail going out twice; this
         * is what stops the claim turning a duplicate into a disappearance.
         * Deliberately generous: releasing early re-creates the very bug the
         * claim exists for, because the first worker may still be sending.
         */
        'claim_lease_minutes' => (int) env('MARKETING_CLAIM_LEASE_MINUTES', 15),
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
    | confirmation_throttle is the only limit in this stack keyed on the
    | RECIPIENT. Everything else — the per-IP throttle on a website, the
    | per-brand throttle on an API — bounds how fast a SENDER may act, and none
    | of them can see that every one of those requests is aimed at the same
    | person. A public form plus a verified sending domain is otherwise a way
    | to have somebody else's mailbox filled with confirmation requests that
    | carry your reputation.
    |
    | Two tiers: `per_list` is the tight one a real subscriber meets (they need
    | one mail, and an hour is a fair wait to ask again), `per_mailbox` bounds
    | everything at once so the tight limit cannot be walked across every list
    | and every brand on the install. Set `enabled` to false only where the
    | sign-up endpoint is not publicly reachable.
    |
    | confirmation_ttl_hours retires a confirmation link that was never used.
    | 0 disables it. Seven days is long enough for a holiday and short enough
    | that a link found in a backup years later is not a way in.
    |
    | confirm_requires_post decides whether opening the link is enough. It is
    | not, by default: mail gateways scan links, and a scanned link used to
    | mean a subscription nobody had agreed to, recorded with a timestamp that
    | looked exactly like a real confirmation. Turning this off restores the
    | one-click flow and, with it, that problem.
    |
    */

    'subscriptions' => [
        'double_opt_in' => true,
        'honeypot' => 'website',

        'confirmation_throttle' => [
            'enabled' => env('MARKETING_CONFIRMATION_THROTTLE', true),

            // Which cache store counts. Null uses the application default —
            // and then checks it. A store that cannot increment atomically
            // (the file driver, which is Laravel's own default) is refused
            // rather than trusted: it reads, adds and writes back in three
            // steps, so parallel sign-ups all read the same number and all
            // pass, and it reports success whether or not the write landed.
            // When the store is refused, NO confirmation mail is sent.
            'store' => env('MARKETING_CONFIRMATION_THROTTLE_STORE'),
            'per_list' => (int) env('MARKETING_CONFIRMATION_PER_LIST', 1),
            'per_list_window_minutes' => (int) env('MARKETING_CONFIRMATION_PER_LIST_WINDOW', 60),
            'per_mailbox' => (int) env('MARKETING_CONFIRMATION_PER_MAILBOX', 5),
            'per_mailbox_window_minutes' => (int) env('MARKETING_CONFIRMATION_PER_MAILBOX_WINDOW', 1440),
        ],

        'confirmation_ttl_hours' => (int) env('MARKETING_CONFIRMATION_TTL_HOURS', 168),

        'confirm_requires_post' => env('MARKETING_CONFIRM_REQUIRES_POST', true),
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

    /*
    |--------------------------------------------------------------------------
    | Fuss
    |--------------------------------------------------------------------------
    |
    | Die Anbieterkennzeichnung, die unter jeder Werbemail stehen muss (§ 5 DDG,
    | frueher § 5 TMG). Leer ausgeliefert: ein Addon kann die Anschrift seines
    | Betreibers nicht erfinden, und eine erfundene waere schlimmer als keine.
    |
    | Ist sie gesetzt und die gerenderte Mail enthaelt sie nicht, haengt der
    | Renderer sie an. Das ist ein Netz, kein Ersatz fuer eine Vorlage, die es
    | richtig macht — aber es faengt den Fall, der sonst lautlos durchrutscht:
    | eine Vorlage mit Abmeldelink und ohne Anschrift, etwa das mitgelieferte
    | Ersatzlayout.
    |
    | Auf einem Host mit mehreren Marken gehoert der Wert je Marke gesetzt.
    |
    */

    'footer' => [
        'postal_line' => env('MARKETING_POSTAL_LINE'),
    ],

    'tracking' => [
        'opens' => true,
        'clicks' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeline — the mails on a LeadHub contact's record
    |--------------------------------------------------------------------------
    |
    | Every mail this addon sends is written onto the recipient's LeadHub
    | timeline, so the question "what has this person had from us, and did they
    | read it" is answerable where somebody actually asks it — on the contact.
    |
    | Nothing is written for an address with no contact: a tracking pixel must
    | not be able to create a CRM record.
    |
    | `types` narrows what is written. An installation sending to fifty thousand
    | people may not want a row per open on every contact; leaving it empty
    | means all six kinds. The constants are on
    | `Integrations\Leadhub\TimelineRecorder`.
    |
    */

    'timeline' => [
        'enabled' => true,
        'types' => [],
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
    | Frequency cap
    |--------------------------------------------------------------------------
    |
    | An upper bound on how much MARKETING mail one contact receives in a
    | rolling window. Off by default, and it has to stay that way: an addon
    | that started holding a running installation's mail back because a package
    | was updated would be a worse failure than any amount of mail.
    |
    | The exceptions are the rule. Every outgoing mail carries a classification
    | — marketing, transactional, digest or reminder (see
    | Goldnead\Marketing\Contracts\MailClass) — and the cap acts on `marketing`
    | alone. A community digest does not eat the budget, a password reset is
    | never delayed, and an event reminder goes out whatever the count says.
    | A campaign's class is set on its edit screen and defaults to `marketing`:
    | opting out is an act, never an omission.
    |
    | The decision is taken when a message is actually sent, not when it is
    | queued. A job that waited three days behind a throttle is measured
    | against the window that ends now.
    |
    | A capped message is DEFERRED, not dropped: it goes back on the queue
    | `retry_after_minutes` later, up to `max_deferrals` times. Only then is it
    | discarded — with `status = capped` on the row and a warning in the log,
    | because "why did I never get the March issue" has to have an answer.
    |
    | `window_hours` is stated in hours rather than days so the arithmetic has
    | one unit end to end: 168 is the roadmap's "three mails in seven days".
    |
    */

    'frequency_cap' => [
        'enabled' => env('MARKETING_FREQUENCY_CAP', false),
        'max' => (int) env('MARKETING_FREQUENCY_CAP_MAX', 3),
        'window_hours' => (int) env('MARKETING_FREQUENCY_CAP_WINDOW_HOURS', 168),

        'defer' => [
            'retry_after_minutes' => 1440,
            'max_deferrals' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Newsletter web archive
    |--------------------------------------------------------------------------
    |
    | A public web version of a campaign, on a stable readable URL — no token,
    | so it can be linked, shared and indexed. It is not the same page as the
    | token link in a mail: that one is personalised, this one is deliberately
    | not.
    |
    | Visibility is per campaign and OFF by default. Nothing appears here until
    | an editor releases a campaign from its report page, because a campaign can
    | carry a price, a segment's context or an individual address, and none of
    | that belongs on the open web because a package was updated.
    |
    | `prefix` is the path the three pages live under — the index, `feed.xml`,
    | and one page per campaign at `{prefix}/{handle}`. Unlike the endpoints
    | above it carries no bang: those are machine routes, this is a page for
    | readers.
    |
    | `neutral_name` replaces `{{ first_name }}` and `{{ name }}` in the web
    | version. There is no recipient, so a greeting has to be addressed to
    | somebody — leave it null to use the translation
    | (`marketing::public.archive_neutral_name`), or set the word your
    | newsletter would actually use.
    |
    | Multi-brand: the index and the feed show the brand that is current for the
    | request. A public request carries no session, so unless the application
    | resolves a brand for its front end, that is the default brand. A campaign
    | page derives its brand from the handle, the same way the subscribe
    | endpoint derives one from a list handle. Give each brand its own `prefix`
    | if their archives must be separately addressable.
    |
    */

    'archive' => [
        // Off unless the site asks for it. The archive claims a readable path
        // — `newsletter` by default — and a site that already has a page there
        // would lose it to a `composer update`. That happened on
        // adriangoldner.com, whose own /newsletter page stopped rendering the
        // moment this addon was upgraded. A package may not take a public URL
        // from its host without being asked.
        'enabled' => env('MARKETING_ARCHIVE', false),
        'prefix' => env('MARKETING_ARCHIVE_PREFIX', 'newsletter'),
        'title' => env('MARKETING_ARCHIVE_TITLE'),
        'neutral_name' => null,
        'feed_limit' => 20,
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
