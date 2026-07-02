# Changelog

## 1.0.0 — 2026-07-02

Initial release.

- Boot-order regression tests for the sibling-addon bridges: deferred
  app->booted() registration with trailing retry, no-mark-booted while the
  sibling binding is absent, and idempotent re-boot (mirrors the LeadHub
  fix from statamic-leadhub@9fd6d6a).

- Mailing lists with per-list double opt-in and public subscribe endpoint
  (honeypot-guarded) plus `{{ marketing:subscribe }}` Antlers tag.
- Campaigns with Antlers content, reusable email templates, preview, test
  send, scheduling (`marketing:send-scheduled`), and queued batch delivery
  with optional throttling.
- Open pixel + signed click tracking, per-campaign reports, per-recipient
  message log.
- Tokenized unsubscribe pages and RFC 8058 one-click unsubscribe headers,
  optional global opt-out.
- LeadHub integration (hard dependency): contact upsert + timeline events on
  subscribe/unsubscribe, `list:{handle}` contact tags, opt-out on hard
  bounces/complaints.
- ESP feedback processing (generic/Mailgun/Postmark) — exposed as the
  `marketing.process_esp_event` inbound action when statamic-webhook-manager
  is installed; marketing events double as outbound webhook triggers.
- statamic-automations integration: `marketing.subscribed` /
  `marketing.unsubscribed` / `marketing.campaign_sent` triggers and
  `marketing.subscribe` / `marketing.unsubscribe` / `marketing.send_campaign`
  actions.
- Dual storage for definitions: flat YAML under `content/marketing/`
  (default) or Eloquent (`MARKETING_DRIVER=eloquent`); runtime data always in
  `marketing_*` tables.
- Control Panel: Dashboard, Lists (incl. subscriber management), Campaigns
  (composer + report), Templates — Inertia + Vue 3 with Statamic UI
  components, English and German translations.
