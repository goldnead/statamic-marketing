# Changelog

## 1.1.0 — 2026-07-03

### Added — send to segment

- **Campaign audience narrowing via LeadHub segments.** A campaign can now target an optional **segment** in addition to its list. At send time the audience is `subscribed list members ∩ LeadHub::segmentMemberIds(handle)`, resolved live. The segment only ever *narrows*: consent is always taken from the list subscription, so a segment member who is not a subscribed list member (or who unsubscribed) never receives the campaign, and a subscriber with no linked LeadHub contact is excluded when a segment is set. No segment = the whole list, exactly as before (**backward compatible**).
- **Graceful degradation.** The facade call is guarded with `method_exists(LeadHub::getFacadeRoot(), 'segmentMemberIds')`. If the installed LeadHub predates segments, the filter is ignored (whole-list send) with a single logged warning, and the CP segment picker hides itself — no fatals.
- **CP segment selector.** The campaign form shows a segment dropdown (only when segments are available) with a live member count next to each option.
- **`segment_handle`** added to the campaign schema/data/repositories (eloquent + flat).

### Requirements

- Requires `goldnead/statamic-leadhub` **^1.1** (for the segments API). Merges after LeadHub v1.1.0 is tagged.

### Notes

- Suite green on both drivers: flat **74 passed + 7 skipped**, eloquent **73 passed + 8 skipped** (baseline 66 + 7). New coverage: intersection, consent precedence (segment member not subscribed / unsubscribed segment member never receives), no-linked-contact exclusion, backward compatibility, and graceful degradation when LeadHub lacks segments.

## 1.0.1 — 2026-07-02

### Fixed

- **Eloquent-users compatibility.** The CP base controller called Statamic-only methods (`hasPermission()`, `isSuper()`) on the raw authenticated user. On sites using the eloquent users repository the auth user is a plain model (e.g. `App\Models\User`), so every Marketing CP page crashed with a `BadMethodCallException`. Permission checks now go through Laravel's Gate (`$user->can()`, which Statamic wires up via `Gate::after` for both user drivers). Regression-tested with `statamic.users.repository=eloquent` and a plain `Authenticatable` model.

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
