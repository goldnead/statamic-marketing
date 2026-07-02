# Statamic Marketplace Copy

Source material for the Statamic Marketplace listing.

---

## Title

**Statamic Marketing**

## Tagline / Short Description

Email marketing and newsletters native to Statamic — mailing lists, double opt-in, campaigns, batch sending, and open/click tracking, right inside your Control Panel.

## Long Description

Statamic Marketing is a Mailcoach-style email marketing engine built specifically for Statamic. Instead of syncing your audience to an external newsletter SaaS and composing campaigns in someone else's editor, everything lives where your content already is: lists, templates, and campaigns as flat YAML files under `content/marketing/` (version-controlled, the Statamic way), campaigns written in Antlers, and a Control Panel that feels exactly like the rest of Statamic 6 (Inertia + Vue 3 + the native `@statamic/cms` UI).

Subscribers are [LeadHub](https://github.com/goldnead/statamic-leadhub) contacts — every signup, confirmation, and unsubscribe lands on the contact's timeline, tags the contact with the list, and respects the CRM's do-not-contact flag. Delivery runs through your own Laravel mailer (SES, Mailgun, Postmark, SMTP, …) via queued jobs with a configurable throttle. Opens and clicks are tracked first-party — an open pixel and signed redirect links, no third-party tracking domain.

Compliance is built in: per-list double opt-in with tokenized confirmation links, one-click unsubscribe headers (RFC 8058), tokenized unsubscribe pages, and automatic opt-outs on hard bounces and spam complaints (fed by your ESP's webhooks through Webhook Manager).

## Positioning Sentence

Your audience, your content, your mail server — newsletters shouldn't require renting your subscriber list back from a SaaS. Statamic Marketing keeps the whole loop inside Statamic.

## Key Features

- Mailing lists with per-list double opt-in and honeypot-guarded signup endpoint
- `{{ marketing:subscribe }}` Antlers tag for instant frontend forms
- Campaigns composed in Antlers (`{{ first_name }}`, `{{ unsubscribe_url }}`, …) with reusable HTML template layouts
- Live preview, test sends, scheduling, and send-now from the CP
- Queued batch delivery through any Laravel mailer, with per-minute throttling
- First-party open & click tracking, per-campaign reports (open/click rate, bounces, unsubscribes)
- RFC 8058 one-click unsubscribe + tokenized unsubscribe pages
- ESP feedback processing: Mailgun/Postmark/generic bounce & complaint webhooks
- Subscribers are LeadHub contacts: timeline events, `list:{handle}` tags, do-not-contact sync
- Flat-file storage for lists/campaigns/templates (or Eloquent via `MARKETING_DRIVER`)
- Granular CP permissions, English + German translations
- Deep sibling integrations: Automations triggers/actions + ready-made workflow templates, Webhook Manager outbound triggers + inbound ESP action

## Who It's For

- Statamic sites that want newsletters without an external email SaaS
- Agencies standardizing on the goldnead addon family (LeadHub, Automations, Webhook Manager)
- Creators who want their audience version-controlled and self-hosted

## Who It's *Not* For

- High-volume ESP replacement (millions of sends/day) — bring a transactional provider and throttle accordingly
- Teams that need a drag-and-drop email designer — templates are hand-crafted HTML/Antlers

## Requirements

- Statamic 6, PHP 8.2+
- `goldnead/statamic-leadhub` (subscribers are contacts)
- A queue worker + the Laravel scheduler

## Screenshots

| File | Caption |
| --- | --- |
| `screenshots/dashboard.png` | Marketing dashboard — audience and recent campaign performance at a glance |
| `screenshots/lists.png` | Mailing lists with double-opt-in status and live subscriber counts |
| `screenshots/list.png` | List detail — subscriber management, filters, and stats |
| `screenshots/campaign-edit.png` | Campaign composer — Antlers content, sender, scheduling, test send |
| `screenshots/campaign-report.png` | Campaign report — delivery, open & click rates, per-recipient log |
| `screenshots/templates.png` | Reusable HTML email templates |

## Art

- `art/icon.svg` / `art/icon.png` — addon icon (512×512)
- `art/cover.png` — marketplace cover (1200×630), source: `art/cover.html`
