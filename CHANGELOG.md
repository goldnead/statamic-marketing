# Changelog

## 1.6.0 — 2026-07-28

### Added — the flat driver works under multi-brand

The plan was to remove this driver. Multi-brand was said to require eloquent
storage, because a YAML file carries no brand, so the flat driver looked like a
dead end that only kept people from the driver that works. Two findings turned
that around.

The first: **adriangoldner.com runs five real mailing lists on it.**
`content/marketing/lists/{newsletter,chorleitung,saenger,events,offers}.yaml`,
`MARKETING_DRIVER` unset, so the default. A removal would have stranded every
one of them, and there was nothing wrong with them.

The second is the one that made the work small. **The flat driver only ever
held definitions** — lists, campaigns, templates. `Subscription`, `Message` and
`MessageEvent` are Eloquent models with `HasBrand` in every driver, always were.
The consent data, the part that must never bleed across brands, was never in
those files. What was missing was not isolation of anything sensitive; it was
the definitions saying which brand they belong to.

**A brand is a directory, not a key in the file.** Under multi-brand:

```
content/marketing/acme/lists/newsletter.yaml
content/marketing/contoso/lists/updates.yaml
```

A `brand:` key inside the file was the alternative and was rejected. The handle
is the filename here, so a key would give every definition two identities that
can disagree, and reading one brand's lists would mean opening every other
brand's files to find out they are not yours. Worse, a missing or misspelt key
falls through to the default brand — a leak that looks like a typo. With a
directory the isolation is structural: a brand's read never opens another
brand's file, and being in the wrong place is visible in `ls` and in a diff.

**Nothing has to move for an install to keep working.** Files in the pre-1.6
layout are read as the default brand's — and as no other brand's, ever. A
single-brand install keeps writing there too, so its content directory looks
exactly as it did in 1.5. `php artisan marketing:migrate-flat-brands` moves
them into the brand directory once a second brand exists; `--dry-run` prints
the moves and touches nothing, `--brand=` picks the target. It only moves,
never overwrites and never deletes, refuses on conflict, and a second run finds
nothing to do. An update that opens to empty lists and a command that repairs
it afterwards was not an acceptable shape for this.

### Fixed — the public subscribe endpoint had no brand to find, in either driver

Every other public route derives its brand from a token: one token, one record,
one brand. A subscribe form carries no token. It carries a list handle, and
until now that was traced back to a brand through `MailingListRecord` — an
Eloquent model that does not exist in flat storage. On a flat multi-brand
install the endpoint therefore ran with no brand at all, the store failed
closed, and the list the form named did not exist. Every public sign-up, 404.

The lookup now goes through `HandleOwnership`, which answers for both drivers
— a query in one, a path in the other — and keeps the guarantees brand-context
established unchanged: two owners throw rather than being guessed between, no
owner sets no brand and leaves the response exactly as it was, and the brand is
always set explicitly so a long-lived worker cannot serve one visitor under the
previous visitor's brand.

### Fixed — list handles were unique per brand, which is the one thing they must not be

This is what the middleware rests on, and it was not true. The brand-scoping
migration turned `marketing_lists.handle` into a `(brand_id, handle)` unique —
correct for campaigns and templates, wrong for lists, because brand-context
states the precondition plainly: a column that is unique only *per brand* must
never be used to derive a brand from. Two brands could each own a list called
`newsletter`, and the next sign-up for that handle would raise
`AmbiguousBrandRecord` — the form dead in both brands at once, and no way to
tell from the outside which brand the visitor meant.

The across-all-brands unique is restored, and both drivers now enforce it
rather than assume it: the flat store refuses the write, and the control panel
asks first, so an editor gets a message at the handle field naming the brand
that holds it instead of a 500. An install that already has the same list
handle in two brands stops the migration with both names — that state already
breaks sign-ups and cannot be resolved by picking a winner.

### Fixed — `marketing:send-scheduled` sent nothing at all under multi-brand

A console run has no session, so no brand is current, and both drivers then
answer with nothing. The command printed "No campaigns due." every minute
forever while every scheduled campaign quietly missed its date — the silent
failure `RunsForEachBrand` (brand-context 1.3.0) exists for, still unfixed
here. It now walks every brand, with `--brand=` to restrict a run. Single-brand
installs run the body once, exactly as before.

### Why 1.6 and not 2.0

A major would have been right if this forced existing installs to act. It does
not. A single-brand flat install updates and keeps its layout, its paths and
its behaviour unchanged — the store writes to `content/marketing/lists/…` as
long as multi-brand is off, and the new command is only needed once a second
brand exists. adriangoldner.com pins `^1.0` and would not have received a 2.0;
receiving this is the point, because it is the install that stays on the
pre-1.6 layout and must keep working.

### Notes

- Suite green on both drivers: flat **136 passed + 7 skipped**, eloquent
  **135 passed + 8 skipped** (baseline 104 / 7). Every part was verified to
  fail without its implementation, by removing it and re-running.
- Cross-brand coverage is the bulk of it: two brands with their own lists,
  campaigns and templates seeing nothing of each other; a public sign-up
  landing in the brand that owns the list and not in the default one; an
  unknown handle setting no brand and not inheriting the previous request's;
  the pre-1.6 files readable by the default brand and invisible to every other;
  the migration losing nothing, refusing rather than overwriting, and being a
  no-op the second time.

## 1.5.3 — 2026-07-28

### Fixed — the rest of the control panel still swallowed every rejection

1.5.2 fixed the campaign form because that is where the reused-handle guard runs. The gap was never limited to that one form: no other page in this control panel rendered what the server sent back either. Creating a list, renaming a list, creating a template, adding a subscriber, sending a test mail, scheduling a send — every one of them answered a rejected input the same way. Nothing was written, nothing was said, and the button looked broken. That is worse than the bug a guard prevents, because a person who cannot see why their input was refused will try the same thing again.

Errors now appear **at the field they belong to**, using the `error` prop Statamic's `Field` component already has — the same thing LeadHub 1.5.0 does for its contact form, so the two addons behave alike. A summary above the form was the cheaper option and would have been the wrong one: the sender fields sit in a sidebar, and a red line at the top of the page does not tell you which of eleven inputs is the problem.

Not every rejection maps to an input. A test send refused because the campaign has no list arrives under a key no field carries. Those go into a collected block above the form, so nothing the server says can fall through the floor. Both paths, not one: a page that only had the summary would have hidden the field errors' location, and a page that only had field errors would have dropped everything else.

The three listing pages send nothing but delete requests, and the server currently has no rejection for a delete. They were wired up anyway, so that a delete guard added later is not silently swallowed a second time.

**Guarded structurally, not by a browser test.** There is no JS test runner in this addon and this release does not introduce one. Instead `CpValidationVisibilityTest` reads the page components: every function that submits must handle the rejection, every submitting page must have somewhere to put an unassignable error, and every field the controllers validate must be rendered somewhere. A form added without error handling fails the suite.

## 1.5.2 — 2026-07-27

### Fixed — validation errors were invisible in the campaign form

Found while photographing the 1.5.0 fix: the rejected handle worked exactly as intended, and the screen showed nothing at all. The request came back with errors, nothing was saved, and Save simply looked dead. A guard nobody can see is barely better than the silent wrong send it replaced, so the form now renders what came back.

The same gap exists elsewhere in this control panel — no page in it rendered validation errors — but only the campaign form is fixed here, because that is the one this release's guard runs in.

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
