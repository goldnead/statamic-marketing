# Changelog

## 1.5.1 — 2026-07-27

### Fixed — the e-mail field fix in 1.5.0 did not work

1.5.0 replaced `flex-1` with `flex-1 min-w-56`, which was the right diagnosis and the wrong remedy: Statamic's `Field` brings its own `min-w-0`, and between two utilities of equal specificity the stylesheet order decides — so the column still computed to zero width and the neighbouring field still sat on top of it. Measured in a running control panel rather than reasoned about: 26 px before, 313 px after. The field now carries an explicit width, which is what its two neighbours already did.

## 1.5.0 — 2026-07-27

### Fixed — the public routes worked for nobody under multi-brand

Confirmation links, unsubscribe links and open/click tracking are opened without a session, so no brand was current and the fail-closed scope hid the very record the token pointed at. A subscription could never be confirmed and stayed pending forever; every unsubscribe link in every sent mail led to a 404; and tracking was the quiet one — the pixel returned 200 and the redirect returned 302 while nothing at all was stored, so campaign statistics sat at 0 % and nothing looked broken.

The brand now comes from the token, which belongs to exactly one record (`SetBrandFromRouteValue`, brand-context 1.4.0). Each column used for this carries a unique index across all brands; that is the precondition, and the lookup throws rather than guesses if it is ever violated. An unknown token still does exactly what it did before: nothing.

**Multi-brand requires the eloquent storage driver.** Flat-file lists live in YAML and carry no brand at all, so the public subscribe endpoint has nothing to derive one from.

### Fixed — one-click unsubscribe answered 419 to every mail provider

The CSRF exclusion on the RFC 8058 route named `ValidateCsrfToken`, but the class in the stack is `PreventRequestForgery` — Laravel renamed it, and excluding a name that is not there matches nothing silently. Gmail and Outlook call this endpoint themselves and read a 419 as a broken unsubscribe path, which is the kind of thing that costs deliverability. All known names are now listed.

### Fixed — reusing a campaign handle reported a send that never happened

Deleting a campaign leaves its delivery rows behind on purpose: they are the record of what went to whom. But a message is identified by campaign handle plus subscriber, so a new campaign on the same handle inherited them, skipped every recipient as already sent, finished instantly and reported success — with not one mail sent. Creating a campaign on a handle that already has delivery history is now refused, with an explanation. History is kept, and no send is ever claimed that did not happen.

### Fixed — an editor's addition was confirmed and asked to confirm at the same time

Adding a subscriber in the control panel deliberately bypasses double opt-in, but it did so *after* the subscription was written — by which time the confirmation mail was already on its way. The person was set to subscribed and asked to confirm the same thing. The decision now happens before writing (`skip_confirmation`); public sign-ups are untouched.

### Fixed — the e-mail field in "add subscriber" was unusable with a mouse

`flex-1` alone gave it a flex-basis of zero, so it collapsed to a sliver its neighbour overlapped.

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
