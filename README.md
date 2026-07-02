# Statamic Marketing

Email marketing and newsletters directly inside your Statamic 6 Control Panel —
mailing lists, double opt-in, campaigns, batch sending, and open/click tracking.
Think Mailcoach, but native to Statamic and built on top of
[LeadHub](https://github.com/goldnead/statamic-leadhub) contacts.

## The addon family

| Addon | Role |
| --- | --- |
| [statamic-leadhub](https://github.com/goldnead/statamic-leadhub) | CRM: contacts, tags, timeline — **required**, subscribers are LeadHub contacts |
| [statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager) | Optional: ESP feedback webhooks in (bounces/complaints), marketing events out |
| [statamic-automations](https://github.com/goldnead/statamic-automations) | Optional: marketing triggers & actions for visual drip workflows |
| **statamic-marketing** | Lists, opt-in, campaigns, sending, tracking |

## Features

- **Mailing lists** with per-list double opt-in (confirmation mail + tokenized
  confirm link) and honeypot-guarded public subscribe endpoint.
- **Campaigns** composed in Antlers (`{{ first_name }}`, `{{ name }}`,
  `{{ email }}`, `{{ unsubscribe_url }}`, …), wrapped in reusable **email
  templates**, with preview, test send, scheduling, and send-now.
- **Queued batch sending** through any Laravel mailer with configurable
  throttle, per-recipient message records, and automatic finalization.
- **Tracking**: open pixel, signed click redirects, per-campaign reports
  (open/click rates, bounces, unsubscribes).
- **Unsubscribes** via tokenized link plus RFC 8058 one-click
  (`List-Unsubscribe` / `List-Unsubscribe-Post` headers), optional global
  opt-out to LeadHub's `do_not_contact`.
- **LeadHub native**: subscribing upserts the contact, records timeline events
  (`marketing.subscribed` / `marketing.unsubscribed`), and tags contacts with
  `list:{handle}`. Hard bounces and complaints opt the contact out.
- **Flat-file first**: lists, campaigns, and templates live as YAML under
  `content/marketing/` (version-controllable, the Statamic way) — or in the
  database via `MARKETING_DRIVER=eloquent`. Runtime data (subscriptions,
  messages, events) is always Eloquent.
- **Sibling integrations** (auto-detected, both optional):
  - *Webhook Manager*: marketing events become outbound webhook triggers; the
    `marketing.process_esp_event` inbound action maps Mailgun/Postmark bounce
    webhooks onto subscriptions.
  - *Automations*: triggers (`marketing.subscribed`, `marketing.unsubscribed`,
    `marketing.campaign_sent`) and actions (`marketing.subscribe`,
    `marketing.unsubscribe`, `marketing.send_campaign`) in the visual builder.

## Installation

```bash
composer require goldnead/statamic-marketing
php artisan migrate
```

Requires PHP 8.2+, Statamic 6, and `goldnead/statamic-leadhub`.

Publish the config if you want to tweak defaults:

```bash
php artisan vendor:publish --tag=marketing-config
```

Make sure a queue worker is running for campaign delivery, and the Laravel
scheduler for scheduled campaigns (`marketing:send-scheduled` runs every
minute).

## Frontend signup form

```antlers
{{ marketing:subscribe list="newsletter" class="newsletter-form" }}
    <input type="email" name="email" required placeholder="you@example.com">
    <input type="text" name="first_name" placeholder="First name">
    <button>Subscribe</button>
{{ /marketing:subscribe }}
```

Or POST to `{{ marketing:subscribe_url }}` yourself (`email`, `list`, optional
`first_name`, `last_name`, `_redirect`). JSON clients receive
`{ "ok": true, "data": { "status": "pending|subscribed" } }`.

## Configuration highlights (`config/marketing.php`)

| Key | Default | Purpose |
| --- | --- | --- |
| `storage.driver` | `flat` | `flat` (YAML in `content/marketing/`) or `eloquent` |
| `sending.mailer` | app default | Laravel mailer for campaigns |
| `sending.messages_per_minute` | `0` | Throttle for ESP rate limits (0 = off) |
| `subscriptions.double_opt_in` | `true` | Default for new lists (per-list override) |
| `unsubscribe.global_opt_out` | `false` | Also set LeadHub `do_not_contact` on unsubscribe |
| `tracking.opens` / `tracking.clicks` | `true` | Toggle tracking |
| `leadhub.tag_subscribers` | `true` | Tag contacts with `list:{handle}` |

## Testing

```bash
composer install
vendor/bin/pest                     # flat driver (default)
MARKETING_DRIVER=eloquent vendor/bin/pest   # eloquent driver

# Live cross-addon integration suite (installs automations + webhook-manager
# into a throwaway copy; point the *_PATH vars at local checkouts):
AUTOMATIONS_PATH=../statamic-automations \
WEBHOOK_MANAGER_PATH=../statamic-webhook-manager \
scripts/test-siblings.sh
```

CI note: `goldnead/statamic-leadhub` is a private sibling repo, so the GitHub
Actions workflows need a `SIBLING_REPOS_TOKEN` repository secret (a PAT with
read access to it) to check it out next to this package.

## License

MIT
