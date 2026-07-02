# Statamic Marketing — Implementation Plan

A Mailcoach-style email marketing & newsletter addon for Statamic 6, built as the
fourth member of the goldnead addon family:

| Addon | Role |
| --- | --- |
| statamic-leadhub | CRM — contacts, tags, timeline (hard dependency: subscribers ARE LeadHub contacts) |
| statamic-webhook-manager | HTTP transport (optional: ESP feedback webhooks in, marketing events out) |
| statamic-automations | Orchestration (optional: marketing triggers/actions for visual drip workflows) |
| **statamic-marketing** | **Email marketing — lists, double opt-in, campaigns, sending, tracking** |

## Architecture decisions

- **Namespace** `Goldnead\Marketing`, package `goldnead/statamic-marketing`, addon name "Marketing".
- **Storage — dual driver, flat-file first.** Definition entities (mailing lists,
  campaigns, email templates) are low-cardinality content and live as YAML under
  `content/marketing/{lists,campaigns,templates}/` by default (`MARKETING_DRIVER=flat`),
  with an Eloquent driver as the alternative — the same repository-contract pattern
  the sibling addons use. Runtime data (subscriptions, sent messages, open/click
  events) is high-volume write traffic and is **always Eloquent** (`marketing_*`
  tables), mirroring how webhook-manager always stores deliveries in the DB.
- **Subscribers are LeadHub contacts.** A `Subscription` links a list to a contact
  (by uuid + email snapshot). Subscribing upserts the contact through
  `LeadHub::ingest()` (timeline entry, dedup, optional `list:{handle}` tag).
  Unsubscribes write a timeline entry; hard bounces/complaints call
  `LeadHub::optOut()`.
- **CP** is Inertia + Vue 3 using `@statamic/cms/ui` components (Header, Listing,
  Panel, Field, Button, …) — identical look & feel to the siblings and the native CP.
- **Sibling integration is soft** (`class_exists` + deferred boot bridges, exactly
  like LeadHub's `WebhookManagerBridge`): everything works without automations or
  webhook-manager installed.

## Domain

- `MailingList` — handle, name, description, double_opt_in. Flat: `content/marketing/lists/{handle}.yaml`.
- `EmailTemplate` — handle, name, `html` layout containing `{{ content }}`. Flat: `content/marketing/templates/{handle}.yaml`.
- `Campaign` — handle, name, subject, preheader, from/reply-to, list_handle,
  template_handle, Antlers `content`, status `draft → scheduled → sending → sent`,
  scheduled_at/sent_at. Flat: `content/marketing/campaigns/{handle}.yaml`.
  Stats are computed live from the messages table.
- `Subscription` (Eloquent) — list_handle, email(+normalized), contact_uuid, name
  snapshot, status `pending|subscribed|unsubscribed|bounced|complained`, token,
  consent timestamps, source, meta.
- `Message` (Eloquent) — per-recipient send record with status, opens/clicks
  counters, timestamps. `MessageEvent` (Eloquent) — open/click/bounce/… log.

## Flows

- **Subscribe** (public POST, honeypot-guarded, or `{{ marketing:subscribe }}` tag):
  double opt-in → pending + signed confirmation mail; confirm link → subscribed +
  LeadHub upsert + `MarketingSubscribed` event. Single opt-in skips the pending step.
- **Unsubscribe**: token link in every campaign + `List-Unsubscribe` /
  `List-Unsubscribe-Post: One-Click` headers. Optional global opt-out
  (`do_not_contact`) via config.
- **Send**: CP "Send" or `marketing:send-scheduled` (every minute) →
  `StartCampaignJob` snapshots recipients into messages → one queued
  `SendMessageJob` per message (configurable throttle/mailer) → render Antlers
  content into template layout, rewrite links to signed click-tracking redirects,
  append open pixel, send → last message marks the campaign `sent`.
- **Track**: `GET …/o/{uuid}.gif` (open pixel), `GET …/c/{uuid}?url=…` (signed
  click redirect) update counters + events.
- **ESP feedback**: `EspEventProcessor` normalizes bounce/complaint payloads
  (generic/Mailgun/Postmark shapes) → subscription status + LeadHub opt-out.
  Exposed to webhook-manager as inbound action `marketing.process_esp_event`.

## Events (all in `Goldnead\Marketing\Events`)

`SubscriptionPending`, `MarketingSubscribed`, `MarketingUnsubscribed`,
`CampaignSending`, `CampaignSent`, `MessageSent`, `MessageOpened`,
`MessageClicked`, `MessageBounced`, `MessageComplained` — consumed by the
automations bridge (as `marketing.*` triggers) and the webhook-manager bridge
(as outbound webhook triggers).

## Testing

Pest + orchestra/testbench, same harness as LeadHub (manual `bootAddon()`, CP
routes mounted under `/cp`, sqlite memory, `MARKETING_DRIVER` env-switchable):
subscription flow (double + single opt-in), unsubscribe (link + one-click),
campaign send end-to-end with `Mail::fake`, tracking endpoints, flat/eloquent
repository parity, ESP bounce handling, CP route smoke tests (Inertia component
per page), scheduled-send command.
