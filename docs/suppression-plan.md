# Global suppression layer — implementation plan

Status: **plan only, nothing built.** This document is the build spec for a global,
brand-aware suppression layer in `goldnead/statamic-marketing`. It is written to be
executed by an implementing agent; every section names concrete files, columns and
call sites.

Scope note: this plan covers the *suppression* layer only — the authoritative answer
to "may we send to this address at all?", enforced before a send is ever constructed.
It is not a deliverability dashboard and not an ESP abstraction layer.

---

## 1. Why now, and how urgent

The common framing — "needed before a second brand arrives" — is already out of date.
The accurate framing:

- **The second brand already exists.** As of 2026-07-24 the Hub (`goldnead/hub`,
  `hub.gldnr.studio`) runs with a `brands` table holding `default` (#1) and
  `familystack` (#2), and FamilyStack's `recipes` backend is wired to it
  (`HUB_ENABLED=true`). Multi-brand is live, not hypothetical.
- **What has not happened yet is the P4 send handover.** FamilyStack's actual campaign
  and automation sending still runs directly on Resend, outside the Hub.
  `STATE/projects/project-marketing-hub.md` names this as the current `next_action`,
  alongside "globale Suppression-Tabelle nachrüsten".
- **That handover is the real deadline.** The moment FamilyStack's sending moves into
  the Hub, two brands send through one system with no cross-brand suppression. Until
  then the exposure is limited because each brand still sends from its own stack.
- **Volume today is genuinely zero.** The same project file records "bei FamilyStack
  irrelevant, 0 Suppressions". So the backfill in §8 is a formality now and a real data
  migration later — the strongest practical argument for building it before P4, not after.
- **The complaint case is a legal boundary, not a deliverability nicety.** Mailing an
  address again after it has issued a spam complaint is the one failure mode here with
  regulatory exposure. That case gets explicit, non-negotiable test coverage (§9).

So: not an emergency today, but a hard prerequisite for the P4 send handover — which is
the current top-of-queue item. Build it while the data volume is still zero.

### 1.1 Prior art — this has been specified twice and built once, elsewhere

Two archived GoldnerOS backlog objects already cover this ground. Neither was rejected;
both were overtaken by events.

- **`backlog-bounce-und-complaint-handling-mit-suppression-liste` (issue #364,
  2026-07-01, `status: archived`, `triage: answered`).** A specification for
  FamilyStack, scoped to Scaleway TEM. Its two clarifying questions were both answered
  yes (TEM credentials exist and are deployed; the Essential plan's blocklist API is
  available). No implementation followed. It was briefly declared moot on 2026-07-02
  when FamilyStack switched to MailerSend — a decision reversed the same day back to
  Resend, which left #364 unresolved rather than obsolete.
- **`backlog-email-deliverability-end-to-end` (issue #440, 2026-07-02, archived).**
  Independently re-specifies a Resend bounce webhook writing into an `email_suppressions`
  table, and notes the existing suppression sync targets Scaleway rather than Resend.

There is also a **working implementation outside this ecosystem**: the `recipes`
(FamilyStack) repo has `backend/email_suppression.py` with a
`sync_from_tem_blocklist` routine. It is not reusable here — different language, different
stack — but its two recorded defects are directly instructive, and this plan fixes both
by construction:

| Recorded defect (`backlog-fs-di-delivery-reliability`, D13) | How this plan avoids it |
| --- | --- |
| "Email-Suppression kein Unique-Index → Duplikate" | `ms_supp_brand_email_unique` on `(brand_id, email_normalized)`, §3.1 |
| "`remove_suppression` = `delete_one` lässt Adresse trotz Unblock gesperrt" | Suppressions are **released, not deleted** (`released_at`), and the gate filters on it, §3.1 |

The `suppression_sync` cron for that implementation also never registered
(`backlog-reminder-scheduler-jobs-registrieren-nicht`, issue #414) — a reminder that the
scheduled parts of WP8 need a registration test, not just a command.

---

## 2. Where we stand today (verified)

All statements below were verified against the code in this repo and the sibling
addons on 2026-07-26. Anything not verifiable from here is in §11.

### 2.1 What exists

**Bounce/complaint intake exists, but only as a per-list status write.**

`src/Services/EspEventProcessor.php` normalizes ESP feedback and applies it:

- `normalize(array $payload, ?string $provider)` — `match` on `$provider` with cases
  `'mailgun'`, `'postmark'`, and a generic default. **No Brevo, no Resend.**
- `applyBounce()` — on *hard* bounce sets `Subscription.status = bounced` and, if
  `marketing.leadhub.hard_bounce_opt_out` (default `true`), calls `LeadHub::optOut()`.
- `applyComplaint()` — sets `Subscription.status = complained`, same opt-out path via
  `marketing.leadhub.complaint_opt_out` (default `true`).
- `resolveSubscriptions()` — resolves by `message_uuid`, else falls back to
  `Subscription::where('email_normalized', …)` across *all lists in the current brand*.

`src/Integrations/WebhookManager/ProcessEspEventHandler.php` exposes this to
webhook-manager as inbound action `marketing.process_esp_event`, registered by
`src/Integrations/WebhookManager/WebhookManagerBridge.php` behind a `class_exists`
guard and `marketing.integrations.webhook_manager`.

**The ingress transport already exists and must not be rebuilt.**
`goldnead/statamic-webhook-manager` is a full bidirectional webhook platform:

| Capability | Where |
| --- | --- |
| Public inbound endpoint `POST {prefix}/{handle}`, CSRF-exempt, `Route::any` | `routes/inbound.php` → `Http/Controllers/InboundWebhookController` |
| Pipeline: auth → parse → replay-protect → map → dispatch action → respond | `Services/Inbound/InboundRequestProcessor` |
| Declarative payload mapping (dot-paths, transforms, defaults) | `Mappers/MappingEngine` |
| Replay protection, cache-backed, TTL 600s default | `Auth/Support/ReplayProtectionService` |
| Auth verifier registry, pluggable from other addons | `Registries/AuthSchemeRegistry`, `WebhookManager::registerAuthScheme()` |
| Inbound action registry, pluggable from other addons | `Registries/InboundActionHandlerRegistry`, `WebhookManager::registerInboundActionHandler()` |
| Endpoint CRUD in the CP, secrets encrypted at rest | `webhook_inbounds` table, `Cp/InboundController` |

Endpoints are configuration rows, not code — a Brevo endpoint at
`POST /!/webhooks/inbound/brevo-events` needs **no new route, controller or migration**.

**Brand isolation convention is uniform and well established.**
Every stateful table in brand-context / leadhub / marketing carries
`brand_id unsignedBigInteger NOT NULL` + plain index, uses
`Goldnead\BrandContext\Concerns\HasBrand`, and every business-key unique is composite
`(brand_id, …)`. `BrandScope` is a genuine no-op when
`brand-context.multi_brand` (env `BRAND_CONTEXT_MULTI_BRAND`, default `false`) is off,
and fails closed (`whereRaw('1 = 0')`) when it is on and no brand resolves.

The consent-bleed rationale is stated explicitly in
`database/migrations/2026_07_24_100001_add_brand_id_to_marketing_tables.php`, which
reworked the subscription unique to `ms_brand_list_email_unique`
`(brand_id, list_handle, email_normalized)`:

> Without this, brand A holding a subscription for an address would block that same
> address from subscribing — and holding independent consent — in brand B (consent bleed).

That decision constrains §3 and §4 of this plan directly.

### 2.2 What does not exist

- **No suppression table, model or service.** Exhaustive search for
  `suppress|blocklist|blacklist|denylist` across the repo returns nothing but an
  unrelated UI comment. This was never built and never removed.
- **No global "is this address sendable?" question can be asked.** Bounce/complaint
  state lives only as a `status` string on a per-`(brand_id, list_handle)`
  `marketing_subscriptions` row. The same address on two lists carries two
  independent, divergent states.
- **No provider message-id is ever captured.** `src/Jobs/SendMessageJob.php:63` calls
  `Mail::mailer(...)->to(...)->send(...)` and discards the return value.
  `marketing_messages` has no column for it. Inbound events can therefore only be
  correlated by our own `Message.uuid` — which nothing in the outbound path ever
  transmits to the provider. **The correlation loop is open at both ends.**
- **No Brevo or Resend code anywhere** — not in this addon, not in webhook-manager.
- **No soft-bounce counting, no thresholds, no expiry, no provider suppression import,
  no audit trail of why an address was blocked or by whom.**

### 2.3 Two concrete defects the new gate must close

1. **The existing opt-out check fails open.**
   `src/Jobs/StartCampaignJob.php:143` —
   ```php
   protected function contactOptedOut(ContactRepository $contacts, Subscription $subscription): bool
   {
       if (! $subscription->contact_uuid) {
           return false;   // ← no linked contact ⇒ "not opted out" ⇒ send
       }
       ...
   }
   ```
   A subscription whose LeadHub contact upsert never succeeded is exempt from every
   opt-out check. It also runs one `$contacts->find()` per subscriber inside the
   audience loop (N+1).

2. **The generic ESP normalizer defaults to hard.**
   `EspEventProcessor::normalize()`, default branch: `'hard' => (bool) ($payload['hard'] ?? true)`.
   An unmapped soft bounce is treated as permanent and kills a valid subscriber. The
   new classifier must invert this: **unknown severity ⇒ soft.**

### 2.4 Coverage against the ecosystem document

`GoldnerOS/SYSTEM/statamic-platform-addon-ecosystem.md` §6.9 ("Deliverability und
Suppression") names seven building blocks and closes with "Die eigentliche
Zustellinfrastruktur bleibt extern." Mapping them to this plan:

| §6.9 building block | Covered in |
| --- | --- |
| Hard Bounce | §4, `reason = hard_bounce`, global |
| Soft Bounce | §4.2, event-logged, threshold-promoted |
| Complaint | §4, §4.3, brand-scoped and non-releasable |
| Provider Suppression | §4 `provider_import`, WP8 |
| Message ID | §6, `provider_message_id` + custom-header uuid |
| Delivery Status | §6.1, `delivery_status` + `delivered_at` on `marketing_messages` |
| Failure Reason | §3.1 `reason` + §3.2 `payload` audit trail |

The closing constraint is respected: this plan adds **no** outbound transport. It only
consumes feedback. See §12.

---

## 3. Data model

Two tables. One holds current state and answers the gate question; one is an
append-only audit log that makes the state defensible and enables thresholds.

### 3.1 `marketing_suppressions` — current state

```php
Schema::create('marketing_suppressions', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();

    // 0 = global (applies to every brand). Any other value = that brand only.
    // Deliberately NOT nullable: MySQL treats NULLs as distinct in unique
    // indexes, which would silently permit duplicate global rows.
    $table->unsignedBigInteger('brand_id')->default(0);

    $table->string('email_normalized');
    $table->string('reason', 32);              // see §4
    $table->string('source', 64)->nullable();  // brevo|resend|cp|import|api|leadhub
    $table->string('provider_message_id')->nullable();
    $table->foreignId('message_id')->nullable()
          ->constrained('marketing_messages')->nullOnDelete();

    $table->timestamp('suppressed_at');
    $table->timestamp('expires_at')->nullable();   // NULL = permanent
    $table->timestamp('released_at')->nullable();  // released, never hard-deleted
    $table->string('released_by')->nullable();
    $table->string('release_reason')->nullable();

    $table->text('notes')->nullable();
    $table->json('meta')->nullable();
    $table->timestamps();

    $table->unique(['brand_id', 'email_normalized'], 'ms_supp_brand_email_unique');
    $table->index('email_normalized');
    $table->index(['reason', 'expires_at']);
    $table->index('brand_id');
});
```

Notes:

- `ms_supp_brand_email_unique` follows the short-name precedent set by
  `ms_brand_list_email_unique` (MySQL 64-char identifier limit).
- One row per `(brand, address)`. A global hard bounce (`brand_id = 0`) and a
  brand-scoped complaint (`brand_id = 3`) coexist as two rows — correct, because they
  are different facts with different reversibility.
- Suppressions are **released, not deleted.** `released_at` preserves the record; the
  audit log in §3.2 preserves the reasoning. Re-suppression updates the existing row.

### 3.2 `marketing_suppression_events` — append-only audit log

```php
Schema::create('marketing_suppression_events', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->unsignedBigInteger('brand_id')->default(0);
    $table->string('email_normalized')->index();

    $table->string('event_type', 32);   // suppressed|released|soft_bounce|reasserted|imported
    $table->string('reason', 32)->nullable();
    $table->string('source', 64)->nullable();
    $table->string('provider', 32)->nullable();          // brevo|resend
    $table->string('provider_event_id')->nullable();
    $table->string('provider_message_id')->nullable();
    $table->foreignId('message_id')->nullable()
          ->constrained('marketing_messages')->nullOnDelete();
    $table->string('actor')->nullable();                 // CP user for manual actions

    // Idempotency. Nullable-unique is intentional: manual events carry no key and
    // MySQL permits many NULLs. Mirrors leadhub_events.dedupe_key.
    $table->string('dedupe_key')->nullable()->unique();

    $table->json('payload')->nullable();                 // raw provider event
    $table->timestamp('occurred_at');
    $table->timestamps();

    $table->index(['email_normalized', 'event_type', 'occurred_at'], 'ms_suppev_email_type_time');
});
```

`dedupe_key` = `sha1(provider . ':' . provider_event_id . ':' . event_type)`, or when
the provider sends no event id, `sha1(provider . ':' . provider_message_id . ':' . event_type . ':' . email)`.
This is what makes webhook redelivery safe.

### 3.3 Brand scoping — a deliberate, documented exception

**These two models do not use `HasBrand`.** This is the first table in the ecosystem to
opt out, and the reason is safety-critical:

`BrandScope::apply()` adds `where brand_id = currentId()`. Applied to the suppression
table it would **hide every global row** (`brand_id = 0`) from the gate query — a
fail-open bug in exactly the query that must never fail open. The trait's `creating`
hook would additionally stamp `currentId()` over the intended `0`.

Instead:

- `Suppression` and `SuppressionEvent` are plain models with an explicit
  `scopeVisibleTo($query, ?int $brandId)`:
  ```php
  $query->whereIn('brand_id', array_unique([0, $brandId ?? BrandContext::currentId()]));
  ```
- Writes set `brand_id` explicitly from the reason's scope rule (§4), never implicitly.
- The CP listing uses `scopeVisibleTo()` so an editor sees global + own-brand rows,
  with the scope rendered as a badge.

This exception is intentional and must be preserved; a future agent "fixing" these
models to use `HasBrand` would reintroduce the fail-open bug.

---

## 4. Suppression types and semantics

`reason` values, their scope, and their reversibility. **Scope** is the recommendation
argued in §4.1 and is decision point **D1**.

| `reason` | Trigger | Scope | Duration | Reversible? | By whom |
| --- | --- | --- | --- | --- | --- |
| `hard_bounce` | Provider reports a permanent bounce | **global** (`brand_id = 0`) | permanent (`expires_at = NULL`) | yes | CP editor, with a typed confirmation and a logged reason |
| `invalid_email` | Provider reports the address is malformed / non-existent | **global** | permanent | yes | CP editor |
| `soft_bounce_threshold` | N soft bounces within window W (§4.2) | **global** | permanent once promoted | yes | CP editor |
| `complaint` | Provider reports a spam complaint / FBL hit | **brand** (`brand_id = current`) | permanent | **no** | nobody — see §4.3 |
| `manual` | Editor blocks an address in the CP | **brand** | permanent, or `expires_at` if set | yes | CP editor |
| `provider_import` | Sync from the ESP's own suppression list | **global** | permanent | yes | CP editor |

Individual soft bounces are **not** suppressions. They are logged as
`marketing_suppression_events` rows with `event_type = soft_bounce` and only produce a
suppression once the threshold trips.

### 4.1 D1 — global vs. brand-scoped: the recommendation and its reasoning

**This is largely already decided.** The Hub P0 analysis
(`GoldnerOS/TASKS/hub-p0-analyse-bericht.md`) records:

> Einziger Global-vs-Brand-Fall: Hard-Bounce = providerweit global, Unsubscribe/Consent
> = strikt je Brand.

The table above is that position, extended to the reasons the P0 note does not name
(`invalid_email`, `soft_bounce_threshold`, `provider_import` follow hard bounce;
`complaint` and `manual` follow consent). So D1 is a **confirmation**, not an open
question — but it is worth confirming explicitly, because the complaint classification
is the one place where a reasonable person might disagree, and it is the one with legal
weight.

The two candidate positions both have a real argument, so this is decided by *what kind
of fact each reason represents*:

- A **hard bounce is a property of the mailbox.** The address does not exist. It will
  bounce identically from every brand, and each retry from a second brand damages the
  shared sending reputation. Scoping it per brand means brand B is guaranteed to
  re-learn, at its own cost, something brand A already knows. → **global.**
- A **complaint is a property of the relationship.** The recipient objected to *this
  sender's* mail. It says nothing about whether a different brand may contact them, and
  the existing schema already rejects consent bleed across brands in the migration
  quoted in §2.1. Making complaints global would contradict a decision already taken.
  → **brand-scoped.**
- A **manual block** is an editorial act within one brand's context. → **brand-scoped**,
  with a CP affordance to promote it to global for the "this address is abusive
  everywhere" case.

So the recommendation is a **split by fact type, not a single global switch**:
deliverability facts are global, consent facts are brand-scoped. This is derivable from
the existing consent-bleed decision rather than in conflict with it, which is why it is
offered as a recommendation rather than an open question — but it is a genuine product
choice and Adrian should confirm it before build starts.

The schema supports either answer without migration: forcing everything global means
always writing `brand_id = 0`; forcing everything brand-scoped means never writing it.
**A late reversal costs a config change, not a migration.**

### 4.2 Soft bounce threshold

Config-driven, defaults conservative:

```php
'suppression' => [
    'soft_bounce' => [
        'threshold'   => 5,    // consecutive-ish failures within the window
        'window_days' => 30,
    ],
],
```

Evaluation on each inbound soft bounce: count `soft_bounce` events for that address in
scope within `window_days`; at `>= threshold`, write a `soft_bounce_threshold`
suppression. A successful delivery to the address writes no event but **resets the
window** by recording a `reasserted` event — otherwise a long-lived address slowly
accumulates unrelated transient failures until it trips.

### 4.3 Complaints are not releasable through the normal path

A complaint suppression must not be removable by the ordinary CP "release" button.
Rationale: it is the one record with regulatory weight, and an accidental release
directly produces the illegal outcome. Implementation:

- `SuppressionService::release()` refuses `reason = complaint` and throws.
- Removal requires an explicit console command
  (`marketing:suppression-release --email=… --force --reason="…"`) which demands a
  written reason and writes a `released` audit event naming the actor.
- The CP shows the row as permanently locked with the reason surfaced.

This is not a UI preference. It is tested (§9, T3).

---

## 5. Provider feedback channel

### 5.0 Which providers actually matter, and how much

Worth stating plainly, because the naive assumption ("Brevo and Resend, equally") does
not match the deployment:

| Provider | Where | Role | Priority for this plan |
| --- | --- | --- | --- |
| **Resend** | FamilyStack / Hub pilot brand | Real campaign sending today | **Primary.** This is the provider whose bounces will actually arrive. |
| **Scaleway TEM** | Hub, "Default-Transport je Brand" per `project-marketing-hub.md` | Intended default transport for Hub brands | **Significant, and under-specified — see §11.6.** Not named in the original brief; discovered during analysis. |
| **Brevo** | adriangoldner.com only | **Outbound contact sync only.** Not a send path. | **Low.** See below. |

The Brevo picture is narrower than it first appears, and this materially reduces its
weight in the plan. Verified in `adriangoldner.com`:

- `App\Services\Brevo\BrevoContactService` has exactly one method,
  `syncNewsletterSubscription()`, which calls three endpoints:
  `PUT /v3/contacts/{email}`, `POST /v3/contacts/lists/2/contacts/remove`, and a second
  `PUT /v3/contacts/{email}`. List ID `2` is hardcoded.
- It is gated on `BREVO_SYNC_ENABLED` **and** a present API key
  (`SyncNewsletterSubscriptionToBrevo::enabled()`), default **off**, production only.
- It mirrors **only** the `newsletter` list handle, one way, app → Brevo.
- **adriangoldner.com does not send campaign mail through Brevo.** Its own DOI,
  welcome-sequence and campaign mail goes through the site's native Laravel mailer via
  `statamic-marketing`. Brevo's role is that Brevo-side automations mail the contacts
  that get synced into list 2.
- **There is no Brevo webhook receiver anywhere in that project** — verified across
  `routes/api/webhooks.php` (ThriveCart, Cal.com, Mollie only), `routes/web.php`, and
  the addon route files. Nothing flows back from Brevo.

So Brevo suppression is not about protecting a send path this system controls. It is
about *learning* that an address is dead so the sync stops pushing it into Brevo's list.
Useful, but a second-order concern behind Resend and Scaleway. It is still specified in
full below, because the brief calls for it and because the security constraint it forces
(§5.2) is the most important one in this document.

### 5.1 Resend — verified

Event names (verified against Resend's event-types documentation):

`email.sent`, `email.delivered`, `email.delivery_delayed`, `email.bounced`,
`email.complained`, `email.opened`, `email.clicked`, `email.failed`, `email.scheduled`,
`email.received`, `email.suppressed`, plus `suppression.added` / `suppression.removed`
and `contact.*` / `domain.*`.

Verified `email.bounced` payload:

```json
{
  "type": "email.bounced",
  "created_at": "2026-11-22T23:41:12.126Z",
  "data": {
    "broadcast_id": "8b146471-e88e-4322-86af-016cd36fd216",
    "created_at": "2026-11-22T23:41:11.894719+00:00",
    "email_id": "56761188-7520-42d8-8898-ff6fc54ce618",
    "message_id": "<111-222-333@email.example.com>",
    "from": "Acme <onboarding@resend.dev>",
    "to": ["delivered@resend.dev"],
    "subject": "Sending this example",
    "template_id": "43f68331-0622-4e15-8202-246a0388854b",
    "bounce": {
      "message": "The recipient's email address is on the suppression list because it has a recent history of producing hard bounces.",
      "subType": "Suppressed",
      "type": "Permanent"
    },
    "tags": { "category": "confirm_email" }
  }
}
```

Mapping: `data.to[0]` → email, `data.message_id` → provider message-id,
`data.email_id` → provider event correlation, `data.bounce.type` → severity.

**Classify on `type`, never on an enumerated `subType` allowlist.** Resend's own docs are
internally inconsistent here: the webhook page shows `type: "Permanent"` with
`subType: "Suppressed"` and mentions `MessageRejected`, while the bounce-classification
page documents `Permanent` / `Transient` / `Undetermined` with subtypes `General`,
`NoEmail`, `MailboxFull`, `MessageTooLarge`, `ContentRejected`, `AttachmentRejected` —
and no `Suppressed` at all. Rule:

```
type === 'Permanent'  → hard
everything else       → soft   (Transient, Temporary, Undetermined, unknown values)
```

Log the raw `subType` into `meta` for later analysis; never branch on it.

**Signature verification — Resend uses Svix.** Headers `svix-id`, `svix-timestamp`,
`svix-signature`. Verification (verified against Svix's manual-verification docs):

1. signed content = `svix_id + "." + svix_timestamp + "." + raw_body`
2. key = `base64_decode(substr($secret, strlen('whsec_')))`
3. `base64_encode(hash_hmac('sha256', $signedContent, $key, true))`
4. `svix-signature` is space-delimited, each entry prefixed `v1,` — strip the prefix,
   compare each with `hash_equals()`
5. reject if `svix-timestamp` is outside tolerance (use webhook-manager's existing
   300s default)

**The existing `HmacSignatureVerifier` cannot do this** — verified by reading
`statamic-webhook-manager/src/Auth/Verifiers/HmacSignatureVerifier.php`. It signs
`timestamp.body` (two parts, not three), emits hex not base64, uses the secret raw
instead of base64-decoded, and — decisively — line 62-64 splits the header on the first
`=`, which destroys a base64 signature's `=` padding and yields an empty string. A
dedicated verifier is required, not a config tweak.

### 5.2 Brevo — verified, with a real security gap

Transactional event names (verified): `request`, `delivered`, `hard_bounce`,
`soft_bounce`, `blocked`, `spam`, `invalid_email`, `deferred`, `error`, `unsubscribed`,
`opened`, `unique_opened`, `click`, `proxy_open`, `unique_proxy_open`.

Payload fields present on bounce/spam events: `event`, `email`, `id`, `date`, `ts`,
`ts_event`, `message-id`, `subject`, `tag`, `tags`, `sending_ip`, `template_id`,
`contact_id`, `X-Mailin-custom`, and `reason` on bounces.

Mapping to our reasons:

| Brevo `event` | reason |
| --- | --- |
| `hard_bounce` | `hard_bounce` |
| `invalid_email` | `invalid_email` |
| `blocked` | `provider_import` (Brevo's own blocklist already holds it) |
| `spam` | `complaint` |
| `soft_bounce`, `deferred` | soft-bounce event only, no suppression |
| `unsubscribed` | existing per-list unsubscribe path, unchanged |

**Brevo sends no signature.** Verified: Brevo's guidance for securing a notify URL is an
IP allowlist (`1.179.112.0/20`) plus whatever auth the receiving URL itself supports.
There is no HMAC and no shared-secret header. Consequences:

- A Brevo endpoint must be protected by **defence in depth**, not signature checking:
  1. an unguessable endpoint handle (e.g. `brevo-events-7f3a…`) — the inbound route is
     `{handle}`-based, so this costs nothing;
  2. webhook-manager's `BasicAuthVerifier` or `StaticHeaderVerifier` as `auth_type`
     (**open — §11.3**: whether Brevo can be configured to send credentials at all is
     unverified; its "Secure webhook calls" doc page 404s);
  3. IP allowlist on `1.179.112.0/20`.
- **`IpAllowlistVerifier` exists but is not registered.** Verified in
  `statamic-webhook-manager/src/Registries/AuthSchemeRegistry::registerDefaults()`,
  which registers only `NoAuthVerifier`, `StaticHeaderVerifier`, `BearerTokenVerifier`,
  `BasicAuthVerifier`, `HmacSignatureVerifier`. The class is unreachable from the CP.
  One line fixes it (WP2).
- Because Brevo events are unauthenticated in practice, the Brevo path **must** be
  treated as lower-trust: an inbound event may only suppress an address this system
  already knows, never an arbitrary address supplied in the payload. Without this,
  anyone who guesses the endpoint URL can mass-suppress a list. This is the single most
  important security constraint in this document.

  **The exact form of "already knows" differs per provider, and the difference is easy
  to get wrong:**

  | Provider | Required correlation before a suppression may be written |
  | --- | --- |
  | Resend (signed) | Signature valid ⇒ trust the payload. Correlate to a `Message` via §6 for attribution, but do not gate on it. |
  | Brevo (unsigned) | The address must already exist as a `Subscription` in the brand receiving the event. |

  Note the asymmetry, because it is a real trap: the Brevo mail that generates these
  bounces is sent **by Brevo's own automations against list 2**, not by this system
  (§5.0). There is therefore **no `Message` row and no custom header to correlate
  against** — the §6 message-id mechanism does not apply to Brevo traffic at all.
  Requiring a `Message` match for Brevo would silently discard every Brevo event.
  Requiring only "this address is a known subscription" keeps the injection defence
  (an attacker cannot suppress addresses that aren't already ours) while letting real
  events through. Test T10 asserts exactly this boundary.

### 5.3 Where this code lives

Reuse webhook-manager end to end. Per provider, the delta is:

1. `EspEventProcessor::normalize()` gains `'brevo'` and `'resend'` match arms —
   `normalizeBrevo()`, `normalizeResend()`, alongside the existing Mailgun/Postmark ones.
2. One new auth verifier, `ResendSvixVerifier` (handle `svix`), registered from
   `WebhookManagerBridge::boot()` via `WebhookManager::registerAuthScheme()`.
3. Endpoint rows are created in the CP by Adrian — no code, no migration.

**The suppression core must not depend on webhook-manager.** It is a `suggest`, not a
`require`, in `composer.json`. `SuppressionService` and the gate work standalone;
webhook-manager is only the ingress transport. Hosts without it can call
`EspEventProcessor::process()` from their own controller, exactly as today.

---

## 6. Message-ID correlation and delivery status

Without this section a bounce event cannot be attributed to a recipient, and the Brevo
trust constraint in §5.2 cannot be enforced. **This is a prerequisite for the ingress
work packages, not a follow-up.**

### 6.1 What must be written at send time

Add to `marketing_messages`:

```php
$table->string('provider_message_id')->nullable()->index();
$table->timestamp('delivered_at')->nullable();
$table->string('delivery_status', 24)->nullable();  // accepted|delivered|deferred|bounced|complained|failed
```

Two independent correlation keys, because neither is reliable alone:

1. **A custom header carrying our own `Message.uuid`.** Set in
   `src/Mail/CampaignMail.php` alongside the existing `List-Unsubscribe` headers:
   - `X-Mailin-custom: {"marketing_message":"<uuid>"}` — Brevo echoes this field back
     verbatim in webhook payloads (it is in the documented payload field list).
   - `X-Entity-Ref-ID: <uuid>` plus a Resend `tags` entry — Resend echoes `tags` in the
     event payload.
2. **The RFC 5322 Message-ID.** Capture the return value of the send call, which is
   currently discarded at `src/Jobs/SendMessageJob.php:63`:
   ```php
   $sent = Mail::mailer(config('marketing.sending.mailer'))->to(...)->send(...);
   $message->update([
       'provider_message_id' => $sent?->getMessageId(),
       'delivery_status'     => 'accepted',
   ]);
   ```
   This matches Brevo's `message-id` payload field and Resend's `data.message_id`.

Resolution order on an inbound event: custom-header uuid → `provider_message_id` →
`email_normalized`.

Scope note: this mechanism applies to mail **this system sends** — i.e. Resend campaign
traffic and any future Hub sending. It does **not** apply to Brevo, whose mail is sent by
Brevo's own automations against list 2, so no `Message` row exists to correlate to
(§5.0, §5.2). For Brevo the only available key is `email_normalized`, and the
authorisation rule is "must be a known subscription in the brand" rather than "must
match a message".

### 6.2 The correlation caveat

`SentMessage::getMessageId()` returns what the local transport reports. For SMTP relays
that rewrite the Message-ID — **Brevo is known to generate its own** — the stored value
may not equal the `message-id` that arrives in the webhook. This is why the custom
header is the primary key and the Message-ID the secondary. Whether they agree for
Brevo specifically is **open — §11.2**; it is a five-minute check against one real send
and must be done before WP5 is considered complete.

---

## 7. Enforcement point in the send path

### 7.1 The gate

A single service, `Goldnead\Marketing\Services\SuppressionGate`:

```php
public function isSuppressed(string $email, ?int $brandId = null): bool;

/** @return array<string,true> keyed by normalized email — set-based, one query */
public function suppressedAmong(array $emails, ?int $brandId = null): array;
```

`suppressedAmong()` is the form the audience loop uses: one query per chunk, not one per
subscriber. It also fixes the existing N+1 noted in §2.3.

### 7.2 Where it is called

There are exactly three transport call sites in this addon (verified by grepping for
`Mail::mailer`). **All three are gated.**

| # | Call site | Gate |
| --- | --- | --- |
| 1 | `src/Jobs/StartCampaignJob.php` — audience build, inside the `chunkById` closure at line ~54, **before** `Message::firstOrCreate` at line ~69 | Primary. Suppressed addresses never become `Message` rows. |
| 2 | `src/Jobs/SendMessageJob.php:63` — per-recipient send | Defence in depth. Re-check immediately before transport; on hit set `Message.status = skipped` and record the reason. Catches addresses suppressed *during* a long send. |
| 3 | `src/Services/SubscriptionService.php:135` — double opt-in confirmation mail | A hard-bounced address must not receive a confirmation mail either. |
| — | `src/Services/CampaignSender.php:92` — test send | Gated too, but surfaced as a CP validation error rather than a silent skip, so the editor learns *why* their test did not arrive. |

Site 1 is the answer to "a blocked address must not even enter a send." Sites 2-4 exist
because site 1 alone is a time-of-check/time-of-use gap: a campaign snapshotting 20k
recipients can run for hours after the audience was built.

### 7.3 Fail-closed

The gate must fail closed on infrastructure failure, which is the opposite of the
current `contactOptedOut()` behaviour:

- A query exception in `suppressedAmong()` **aborts the campaign** — it does not fall
  through to "nobody is suppressed". `StartCampaignJob` catches, sets the campaign to a
  failed/paused state, logs, and re-raises.
- In `SendMessageJob`, an exception marks that single message failed rather than sending.
- Deliberate contrast with the segment resolver at `StartCampaignJob:128`, which
  intentionally fails *open* (a missing segment sends to the whole list). That is
  correct for segments — segments narrow, they do not grant consent — and wrong for
  suppression. The distinction must be stated in a code comment so it is not
  "harmonised" later.

The existing `contactOptedOut()` check stays as a belt-and-braces second signal, but is
no longer the only one, so the `contact_uuid IS NULL` hole (§2.3) closes.

---

## 8. Migration path for existing data

| Existing data | Action | Why |
| --- | --- | --- |
| `marketing_subscriptions.status = 'unsubscribed'` | **Leave untouched. Do not mirror.** | A per-list unsubscribe is a scoped withdrawal of consent for that list. `marketing.unsubscribe.global_opt_out` already defaults to `false`, i.e. Adrian has explicitly decided a list unsubscribe is not a global opt-out. Promoting them retroactively would silently reverse that decision and destroy legitimate subscriptions on other lists. |
| `marketing_subscriptions.status = 'bounced'` | **Backfill** → `hard_bounce`, `brand_id = 0`, `source = 'backfill'` | Already-recorded deliverability facts that merely lack a queryable home. |
| `marketing_subscriptions.status = 'complained'` | **Backfill** → `complaint`, `brand_id` = the row's own `brand_id` | Same, and brand-scoped per §4.1. |
| `leadhub_contacts.do_not_contact = true` | **Backfill** → `manual`, brand-scoped, `source = 'leadhub'` — **decision point D2** | Makes the email-keyed gate cover contacts too, closing the `contact_uuid IS NULL` hole for existing data. Risk: two sources of truth. Recommendation: backfill once, keep reading both, and treat the suppression table as authoritative going forward. |

The backfill migration is idempotent (`updateOrCreate` on `(brand_id, email_normalized)`)
and writes a matching `imported` audit event per row. It is reversible: `down()` deletes
only rows with `source IN ('backfill','leadhub')`.

**Volume today is effectively zero**, so the backfill is a formality now and a genuine
data migration later. That asymmetry is the strongest practical argument for building
this before the second brand.

---

## 9. Work packages

Ordering is dependency-driven: the data model and gate first (they are the actual
feature), correlation next (it is a prerequisite for trustworthy ingress), provider
ingress last.

| # | Package | Size | Owner |
| --- | --- | --- | --- |
| **WP1** | Schema + models + service. Both migrations (§3), `Suppression` / `SuppressionEvent` models with `scopeVisibleTo()`, `SuppressionService` (suppress / release / classify / threshold), config block under `marketing.suppression`. No behaviour change yet. | **M** | agent |
| **WP2** | `SuppressionGate` + enforcement at all four call sites (§7), fail-closed handling, removal of the N+1. Register `IpAllowlistVerifier` in webhook-manager's `registerDefaults()` (one line, separate PR in that repo). | **M** | agent |
| **WP3** | Backfill migration (§8) + `marketing:suppression-backfill` command for re-runs. | **S** | agent |
| **WP4** | CP surface: suppression listing (global + own brand, scope badge), manual add, release flow with the complaint lock (§4.3), audit-log detail view. | **M** | agent |
| **WP5** | Message-ID correlation: `marketing_messages` columns, custom headers in `CampaignMail`, capture `getMessageId()` in `SendMessageJob`, delivery-status writes. **Includes the real-send verification in §11.2.** | **M** | agent, then Adrian for the verification send |
| **WP6** | `normalizeBrevo()` + `normalizeResend()` in `EspEventProcessor`, routed through `SuppressionService`; severity classifier per §5.1 with unknown ⇒ soft; idempotency via `dedupe_key`. | **M** | agent |
| **WP7** | `ResendSvixVerifier` (§5.1) registered via `WebhookManager::registerAuthScheme()` from `WebhookManagerBridge`. | **S** | agent |
| **WP8** | Provider suppression import: `marketing:suppression-import --provider=brevo|resend`. Pulls the ESP's own list in. | **M** | agent |
| **WP9** | Docs: fold into `docs/PLAN.md` (Domain + Flows), add a `recipes.md` entry, `README.md` config table, `CHANGELOG.md`. | **S** | agent |

### What Adrian must do (an agent cannot)

| Step | Detail | Blocks |
| --- | --- | --- |
| **A1** | Confirm D1 (§4.1) and D2 (§8) before WP1 starts. | WP1 |
| **A2** | Create the inbound endpoint rows in the CP, one per provider, with unguessable handles. | WP6 |
| **A3** | Register the webhook URLs in the Brevo and Resend dashboards and select the event sets from §5. | WP6 |
| **A4** | Generate and store the Resend signing secret (`whsec_…`) and the Brevo endpoint credentials in the endpoint's encrypted `auth_config`. Never in the repo. | WP6/WP7 |
| **A5** | Send one real campaign message through each provider and confirm the correlation keys round-trip (§11.2). | WP5 sign-off |
| **A6** | Decide whether the Brevo endpoint sits behind an IP allowlist at the web-server level in addition to the app-level verifier. | WP2 |

Rough shape: WP1-WP3 are the useful minimum (a working gate with real data). WP5-WP7
are what make it self-maintaining. WP4 and WP8 are quality of life.

---

## 10. Tests

Per the Golden Question — each test names the damage it prevents. Follow the existing
Pest + Testbench harness (`tests/TestCase.php`, sqlite in-memory, both storage drivers).

**Mandatory — legal and money boundaries:**

- **T1 — a complaint-suppressed address is never mailed again.** Suppress by complaint,
  run a campaign whose list contains that address, assert zero `Message` rows and
  `Mail::assertNothingSent()` for it. *Prevents: mailing a complainant again — the one
  regulatory failure in this system.*
- **T2 — suppression survives resubscription.** Suppress, then subscribe the same
  address to the same list via the public endpoint, then send. Assert not mailed.
  *Prevents: the trivial bypass of a suppressed user re-entering through the signup form.*
- **T3 — complaint suppressions cannot be released through the ordinary path.**
  Assert `SuppressionService::release()` throws for `reason = complaint` and the row is
  unchanged. *Prevents: an editor undoing a legal block with one CP click.*
- **T4 — the gate fails closed.** Force the suppression query to throw; assert the
  campaign aborts and `Mail::assertNothingSent()`. *Prevents: a DB hiccup silently
  turning into "send to everyone, including the suppressed".*
- **T5 — global scope actually spans brands.** With `BRAND_CONTEXT_MULTI_BRAND=true`,
  suppress with `brand_id = 0` in brand A, then send a campaign in brand B; assert the
  address is excluded. *Prevents: the exact `HasBrand` fail-open bug §3.3 exists to
  avoid — this test is what stops a future refactor from reintroducing it.*
- **T6 — brand-scoped complaint does not leak.** Complaint-suppress in brand A, send in
  brand B, assert the address **is** mailed. *Prevents: over-blocking that would
  contradict the consent-bleed decision.*

**Worth having — correctness of classification:**

- **T7 — an unknown bounce severity is treated as soft.** Feed a Resend payload with
  `bounce.type = "Undetermined"` and an unrecognised value; assert no permanent
  suppression. *Prevents: the §2.3 defect where an unmapped transient failure
  permanently destroys a valid subscriber.*
- **T8 — soft bounces only suppress at the threshold.** `threshold − 1` events → not
  suppressed; one more → suppressed. *Prevents: a single full mailbox costing a
  subscriber.*
- **T9 — webhook redelivery is idempotent.** Post the same provider event twice; assert
  one suppression and one audit event. *Prevents: duplicate-event storms corrupting
  soft-bounce counts into false suppressions.*
- **T10 — an unauthenticated Brevo event cannot suppress an unknown address.**
  Post a Brevo `hard_bounce` for an address that is not a `Subscription` in that brand;
  assert no suppression is written. Then post one for an address that *is* a known
  subscription; assert it **is** suppressed. Both halves matter — the first is the
  injection defence, the second proves the rule is not so strict that it discards all
  real Brevo traffic (§5.2). *Prevents: anyone who guesses the endpoint URL from
  mass-suppressing the list.*

**Deliberately not tested:** model relationships, `$casts`, CP page rendering, the
migration's column types. Per the repo's test policy these are framework guarantees.

---

## 11. Open points — not verified, must not be assumed

### 11.1 Everything Hub-side (`goldnead/hub`)

**No repo access from this session.** Everything below is from GoldnerOS state files, not
from the Hub itself.

Known from GoldnerOS (second-hand, but consistent across several files):

- The Hub is a headless Statamic instance on Hetzner (`157.90.224.18`), live at
  `hub.gldnr.studio`, consuming all five addons as Composer packages.
- `MARKETING_DRIVER=eloquent` is mandatory there — flat-file storage is explicitly noted
  as not brand-isolatable.
- `brands` holds `default` (#1) and `familystack` (#2); FamilyStack's `recipes` backend
  connects via PR #802 with `HUB_ENABLED=true`.
- Brand sender identity for FamilyStack: `hallo@familystack.de`.

**Unverified, and each one can invalidate part of this plan:**

- **Whether the Hub has a send path that bypasses `StartCampaignJob`.** §7 enumerates
  three transport call sites *in this addon*. If the Hub's API triggers sends another
  way, the gate has a hole and §7 needs a fourth entry. **This is the single most
  important thing to check.**
- Whether the Hub needs its own migration pulled forward, or whether running the addon's
  migrations is sufficient.
- Which env vars the Hub actually sets for `BRAND_CONTEXT_MULTI_BRAND`,
  `MARKETING_MAILER` and the provider credentials — in particular whether multi-brand
  enforcement is actually switched **on** there, since the addon default is `false` and
  the P1 decision was explicitly "Multi-Brand ist NICHT der Default". If the flag is off
  in the Hub, `BrandScope` is a no-op and the brand-scoped half of §4 does nothing.
- Whether the Hub surfaces suppression state in its own UI and would need a read API.
- Whether the Token-Hub-API exposes anything that should also consult the gate.

**Action:** before WP2 is merged, someone with Hub access must confirm (a) the send path
is the one in this addon, and (b) the state of `BRAND_CONTEXT_MULTI_BRAND`. If either
differs from the assumption here, this plan needs a revision, not a patch.

### 11.2 Message-ID round-trip (§6.2)

Whether `SentMessage::getMessageId()` equals the `message-id` Brevo reports, and whether
`X-Mailin-custom` survives a campaign send through the configured mailer, is unverified.
Both are cheap to check with one real send and both gate WP5 sign-off. Do not build the
Brevo correlation logic on the assumption that they match.

### 11.3 Brevo endpoint authentication

Brevo's "Secure webhook calls" documentation page returns 404. It is therefore
**unverified** whether Brevo can be configured to send HTTP Basic credentials or a
custom header to the notify URL at all. Verified: Brevo sends no HMAC signature, and its
published guidance is an IP allowlist on `1.179.112.0/20`. If credentials turn out to be
impossible, the Brevo endpoint rests on the unguessable handle + IP allowlist + the
correlation constraint in §5.2 — which is why that constraint is mandatory rather than
defence in depth.

### 11.4 Brevo marketing vs. transactional webhooks

Brevo splits webhooks into "marketing" and "transactional" categories. **Only the
transactional event names and payload fields are verified above** — the marketing event
names and whether their payload shape differs could not be confirmed from the
documentation.

This matters more than it first looks, given §5.0: the mail that actually reaches
Brevo-synced contacts is sent **by Brevo's own automations against list 2**, not by this
system. Those sends are plausibly "marketing" traffic, so their bounce and complaint
events may well arrive on the marketing webhook — the category this plan has *not*
verified. Confirm which category fires during A3, before assuming the transactional
mapping in §5.2 is sufficient.

### 11.5 Resend bounce subtype enumeration

Resend's two documentation pages disagree (§5.1). The plan sidesteps this by classifying
on `type` only, so no action is needed — but an implementer should not "complete" the
subType list from either page and start branching on it.

### 11.6 Scaleway TEM — named as the Hub's default transport, not researched here

`STATE/projects/project-marketing-hub.md` states "Scaleway TEM als Default-Transport je
Brand". The original brief for this plan named only Brevo and Resend, so **no Scaleway
TEM webhook research was done** and none is asserted below. What is known from GoldnerOS
rather than from Scaleway's documentation:

- TEM credentials exist and are deployed in production (per the answered questions on
  issue #364).
- TEM exposes a **blocklist REST API** that is readable on the free Essential plan
  (confirmed in the same answers) — this is a *pull* model, which would map to WP8
  (`provider_import`) rather than to the webhook path.
- TEM webhooks were described in #364's clarification as **beta, one per domain**, and
  therefore less reliable than the blocklist mirror.

**Unverified and required before Scaleway support is built:** event names, payload
shape, signature/verification mechanism, and whether the blocklist API or the webhook is
the right primary channel. Treat Scaleway as a third normalizer (`normalizeScaleway()`)
slotting into the same `EspEventProcessor::normalize()` match, plus a
`marketing:suppression-import --provider=scaleway` path — but do not build it from
assumption. Scope it as its own work package once researched.

### 11.7 Addon version skew between the Hub and adriangoldner.com

This is a deployment constraint, not a design question, and it will bite whoever ships
this.

`adriangoldner.com/composer.lock` pins **pre-brand-context versions** of the whole
family: `statamic-marketing v1.2.1`, `statamic-leadhub v1.3.0`,
`statamic-webhook-manager v1.2.0`, `statamic-automations v1.3.0`,
`statamic-email-templates v1.2.0` — and **no `goldnead/statamic-brand-context` at all**.
The Hub runs the newer, brand-scoped tags.

The suppression layer is built on `brand_id`, so the release carrying it cannot be
consumed by adriangoldner.com without first upgrading that site through the
brand-context introduction and its `2026_07_24_100001_add_brand_id_to_marketing_tables`
migration. Consequences to decide before release:

- Either adriangoldner.com is upgraded to the brand-context line first, or
- the suppression release is tagged such that adriangoldner.com stays on the old line
  until it is ready.

Silently tagging this into `^1.0` and letting `composer update` pull it into
adriangoldner.com would run the brand migration on that site unannounced. **Do not.**

---

## 12. Explicitly out of scope

Named so nobody builds them by accident:

- **An ESP abstraction / provider driver layer.** This addon sends through the host's
  Laravel mailer and knows nothing about transports. Suppression is inbound-only. Adding
  outbound provider drivers is a separate, much larger decision.
- **Engagement-based suppression** (sunsetting unengaged subscribers). Different problem,
  different data, no legal urgency.
- **Automatic campaign pausing on a bounce-rate threshold.** Needs rate baselines this
  system does not have yet. Revisit once real suppression volume exists.
- **Per-domain or per-ISP throttling.** Deliverability tuning, not suppression.
- **Replacing `leadhub_contacts.do_not_contact`.** It stays LeadHub's concern; the gate
  reads both.
- **Changing per-list unsubscribe semantics.** §8 explains why: `global_opt_out` is
  already a deliberate, defaulted-off decision.
- **A public suppression API.** No consumer identified — though §11.1 flags that the Hub
  may want one. Would need its own auth design.
- **Scaleway TEM support.** Named as the Hub's default transport but not researched;
  §11.6. Its own work package once the blocklist-vs-webhook question is answered.
- **The central Preference Center** (ecosystem doc §6.3), whose scope list is "global
  suppression / brand opt-out / channel opt-out / topic opt-out / list opt-out". This
  plan builds the first of those five and deliberately leaves the other four alone. The
  `reason` + `brand_id` shape in §3.1 is designed to extend into that model later
  (a `channel` / `topic` column is additive), but the Preference Center itself is a
  separate product decision.
- **Retroactive suppression of already-queued messages.** The site-2 gate in §7.2 handles
  this at send time; purging queued jobs is a separate mechanism with its own failure
  modes.

---

## 13. Decision points for Adrian

| # | Decision | Recommendation |
| --- | --- | --- |
| **D1** | Is suppression global across brands, or per brand? | **Confirm the existing P0 position**, extended: deliverability facts (hard bounce, invalid, soft-threshold, provider import) **global**; consent facts (complaint, manual) **brand-scoped**. §4.1. Reversible via config, not migration. |
| **D2** | Backfill `leadhub_contacts.do_not_contact` into the suppression table? | Yes, once, `source = 'leadhub'`, keep reading both signals. §8. |
| **D3** | Should a complaint suppression be releasable at all? | No — console-only with a forced written reason. §4.3. |
| **D4** | Soft-bounce threshold and window. | 5 within 30 days. Conservative; tune once real data exists. §4.2. |
| **D5** | Does the Brevo endpoint get a web-server-level IP allowlist in addition to the app verifier? | Yes if the deployment makes it easy; the correlation constraint (§5.2) is the real protection either way. |
| **D6** | How is this released given adriangoldner.com runs pre-brand-context addon versions? | Decide before tagging: upgrade adriangoldner.com to the brand-context line first, or tag so it cannot be pulled in unnoticed. §11.7. |
| **D7** | Is Scaleway TEM in scope, and via blocklist API or webhook? | Out of scope for this plan — research first, then a separate work package. §11.6. Blocklist-pull looks more reliable than the beta webhook. |
| **D8** | Must this land before the P4 send handover? | Recommended yes — the backfill is free today and expensive later. §1. |
