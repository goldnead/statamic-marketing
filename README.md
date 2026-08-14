# Statamic Marketing

Email marketing and newsletters directly inside your Statamic 6 Control Panel —
mailing lists, double opt-in, campaigns, batch sending, and open/click tracking.
Think Mailcoach, but native to Statamic and built on top of
[LeadHub](https://github.com/goldnead/statamic-leadhub) contacts.

## The addon family

| Addon | Role |
| --- | --- |
| [statamic-leadhub](https://github.com/goldnead/statamic-leadhub) | CRM: contacts, tags, timeline — **required**, subscribers are LeadHub contacts |
| [statamic-brand-context](https://github.com/goldnead/statamic-brand-context) | **Required** as a package. Multi-brand *mode* is off by default; the package is not optional |
| [statamic-suppression](https://github.com/goldnead/statamic-suppression) | **Required**. The gate every send path asks before it delivers |
| [statamic-preference-center](https://github.com/goldnead/statamic-preference-center) | Optional: one preference page over marketing lists, notification types and suppression. Installing it moves every footer link there automatically |
| [statamic-webhook-manager](https://github.com/goldnead/statamic-webhook-manager) | Optional: ESP feedback webhooks in (bounces/complaints), marketing events out |
| [statamic-automations](https://github.com/goldnead/statamic-automations) | Optional: marketing triggers & actions for visual drip workflows |
| **statamic-marketing** | Lists, opt-in, campaigns, sending, tracking |

## Features

- **Mailing lists** with per-list double opt-in (confirmation mail + tokenized
  confirm link) and honeypot-guarded public subscribe endpoint.
- **Campaigns** composed in Antlers (`{{ first_name }}`, `{{ name }}`,
  `{{ email }}`, `{{ unsubscribe_url }}`, …), wrapped in reusable **email
  templates**, with preview, test send, scheduling, and send-now.
  Write greetings so they survive a subscriber who never gave a first name:
  `{{ first_name }}` renders **empty** on a send, and `| default:` does not
  catch it (the modifier does not treat an empty string as missing). Use
  `Hallo {{ first_name or "du" }},` or an `{{ if first_name }}…{{ /if }}`.
  The neutral word in `archive.neutral_name` applies to the public archive
  page only, never to the mail.
- **Segment targeting**: optionally narrow a campaign's audience to a **LeadHub
  segment** (with a live member count in the CP). The audience is `subscribed
  list members ∩ segment members`, resolved at send time — the segment only
  narrows; consent always comes from the list. No segment = the whole list.
  Requires LeadHub ^1.1; degrades gracefully (whole-list send) on older LeadHub.
- **Queued batch sending** through any Laravel mailer with configurable
  throttle, per-recipient message records, and automatic finalization.
- **Tracking**: open pixel, signed click redirects, per-campaign reports
  (open/click rates, bounces, unsubscribes).
- **Newsletter web archive**: a public, linkable web version of a campaign on a
  readable URL (`/newsletter/{handle}`), plus a chronological index and an RSS
  feed, with title, description, canonical and Open Graph tags. Visibility is
  per campaign and **off by default** — a campaign is released from its report
  page after it has been sent. The web version is the depersonalised edition:
  the same renderer, but no open pixel, no rewritten links (an open on a web
  page is not an open of the e-mail) and personalisation placeholders resolved
  to a neutral word. An unreleased campaign answers **404, not 403** — a 403
  would confirm that it exists.
- **Frequency caps**: an upper bound on marketing mail per contact per rolling
  window (default off). Every outgoing mail carries a classification —
  `marketing`, `transactional`, `digest`, `reminder` — and the cap acts on
  `marketing` alone: the community digest does not eat the budget, a password
  reset is never delayed, an event reminder goes out regardless. The decision is
  taken **when the message is sent**, not when it is queued, so a job that
  waited three days is measured against the window that ends now. A capped
  message is deferred and retried, and only discarded — with `status = capped`
  and a log entry — once its deferral budget runs out.
- **Unsubscribes** via tokenized link plus RFC 8058 one-click
  (`List-Unsubscribe` / `List-Unsubscribe-Post` headers), optional global
  opt-out to LeadHub's `do_not_contact`.
- **Unsubscribe, always**: a tokenized link ends one list and says so, with no
  login and no optional package involved. Where
  [statamic-preference-center](https://github.com/goldnead/statamic-preference-center)
  is installed, every footer link goes there instead — one page for all of this
  brand's lists, notification types and the suppression state. Marketing does
  not ship a second copy of that page; the switch is a single resolver
  (`src/Support/PreferenceLink.php`), so nothing has to be reconfigured when the
  addon appears or goes away. The RFC 8058 one-click endpoint stays on marketing
  either way.
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
    `marketing.unsubscribe`, `marketing.send_campaign`, `marketing.send_email`)
    in the visual builder. `marketing.send_email` is the node a drip sequence is
    built out of: it sends one campaign — or one managed `et_template` — to the
    contact in the run, through list consent, suppression, opt-out and the
    frequency cap, in that order. See [docs/sequences.md](docs/sequences.md).

## Screenshots

| | |
| --- | --- |
| ![Marketing dashboard](screenshots/dashboard.png) | ![Mailing lists](screenshots/lists.png) |
| Audience and recent campaign performance at a glance | Lists with double-opt-in status and live subscriber counts |
| ![Campaign composer](screenshots/campaign-edit.png) | ![Campaign report](screenshots/campaign-report.png) |
| Antlers content, sender, scheduling, test send | Delivery, open and click rates, per-recipient log |

## Requirements

| | |
| --- | --- |
| PHP | 8.2+ (8.3+ when running Laravel 13) |
| Laravel | 12 or 13 |
| Statamic | 6 |
| Required addons | `goldnead/statamic-leadhub`, `goldnead/statamic-brand-context`, `goldnead/statamic-suppression` |
| Optional addons | `goldnead/statamic-preference-center`, `goldnead/statamic-webhook-manager`, `goldnead/statamic-automations` |
| Also needed | a queue worker (campaign delivery) and the Laravel scheduler (scheduled campaigns) |

All three required addons are installed for you by Composer. `brand-context` is
required as a *package* even on a single-brand site — only its multi-brand mode
is optional.

## Installation

> **Not yet installable from Packagist.** The three required sibling addons are
> private repositories, and Composer only reads the `repositories` block of the
> *root* project — never of a package it is installing. So the plain
> `composer require goldnead/statamic-marketing` cannot resolve them and will
> fail. Until the siblings are published, add the repositories to your own
> `composer.json` first:

```jsonc
// your project's composer.json
"repositories": [
    { "type": "vcs", "url": "https://github.com/goldnead/statamic-brand-context.git" },
    { "type": "vcs", "url": "https://github.com/goldnead/statamic-leadhub.git" },
    { "type": "vcs", "url": "https://github.com/goldnead/statamic-suppression.git" }
]
```

```bash
composer require goldnead/statamic-marketing
php artisan migrate
```

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
`{ "ok": true, "data": { "confirmation": "sent|throttled|unavailable" } }`, plus
`retry_after_minutes` when the answer is `throttled`. Server-rendered forms get
the same word in `session('marketing.subscribed')`.

**The answer is about the MAIL, not about the subscription.** `sent` means a
confirmation is on its way — and it is also what an address that is already
subscribed or currently suppressed gets, because neither of those may be
disclosed at a public endpoint. `throttled` means this mailbox was asked
recently and none is going out right now; `unavailable` means this installation
could not send one. Both of the last two are true of a person who would
otherwise be told to go and watch an empty inbox, which is what happened before
2.5.0 and what this field exists to stop.

The subscription's own `status` is deliberately not in the response. It answered
`subscribed` against `pending`, which is the membership question — and the shape
of a form that can be used to ask it about anybody. `Sending\ConfirmationResult`
carries the whole argument, including what the field still leaks and why that is
the cheaper of the two prices.

### Sign-up is a public endpoint

Anyone can type anyone's address into a sign-up form. That is not a flaw in the form, it is what a
form is — but it means the confirmation mail is the one message this addon sends to a person who
has not asked for it yet, from a domain whose reputation belongs to you.

Three things follow, and all three are on by default.

**Confirmation mail is limited per recipient**, not per sender. A per-IP limit on the website and a
per-token limit on an API both count how fast a *sender* may act; neither can see that every one of
those requests names the same victim. `Support\DeliveryIdentity` decides what "the same mailbox"
means and is deliberately blunt about it — `Opfer@Gmail.com`, `opfer+1@gmail.com`,
`o.p.f.e.r@gmail.com` and `opfer@googlemail.com` are one inbox and one budget. The consent identity
(`EmailNormalizer`, the consent unique) is untouched and stays conservative, because merging two
addresses there means merging two people's decisions.

A withheld mail says so (`confirmation: throttled`) and the subscription is still recorded as
pending. Until 2.5.0 it said nothing at all, on the reasoning that any difference could be used to
ask whether an address is on a list — which cost a real person their sign-up on 13.08.2026 and
bought less than it looked like: "somebody asked for this mailbox in the last hour" is a statement
about a moment, and after the first submission that somebody is the visitor. What is NOT disclosed,
and is the reason the budget is charged before the suppression gate and on the already-subscribed
path as well, is anything about the person: those two answer `sent` and cost the same, so no
sequence of submissions separates them. If the cache cannot count, nothing is sent and the answer
is `unavailable`.

**The confirmation link is single-use, expiring, and its own token.** `confirmation_token` is
rotated with every confirmation mail and spent on first use; `token` keeps serving unsubscribe and
preference links for years, as it must. A link is refused once the subscription is anything other
than pending, so an old confirmation cannot undo an unsubscribe.

**Opening the link is not agreeing to it.** `GET /confirm/{token}` shows a button and changes
nothing; `POST` confirms. Mail gateways and preview features fetch every URL in a message, and a
subscription created by a scanner is both wrong and, because it carries a plausible timestamp,
hard to tell from a real one afterwards.

## Multi-brand

Optional, off by default. With `goldnead/statamic-brand-context` in multi-brand
mode both storage drivers isolate lists, campaigns and templates per brand —
the eloquent driver by `brand_id`, the flat driver by directory:

```
content/marketing/
  acme/lists/newsletter.yaml
  contoso/lists/updates.yaml
```

**Single-brand installs need to do nothing.** They keep the plain
`content/marketing/lists/…` layout, and files still in it are read as the
default brand's even after multi-brand is switched on. Once a second brand
exists, move them into the default brand's directory:

```bash
php artisan marketing:migrate-flat-brands --dry-run   # show the moves
php artisan marketing:migrate-flat-brands             # do them
```

It only ever moves; it never overwrites, never deletes, and a second run is a
no-op. `--brand=` picks a different target brand.

### Checking the consent guarantee

One address on one list is one consent record, and the database is what
enforces it. `php artisan migrate` reporting success says the migrations ran;
it does not say the constraints they were supposed to leave behind are there.
This asks the second question directly:

```bash
php artisan marketing:consent-integrity            # report only, changes nothing
php artisan marketing:consent-integrity --repair   # rebuild the index, if nothing is in the way
```

It reads the indexes on `marketing_subscriptions` as they are right now and the
rows in it, names any list/address pair holding more than one subscription with
each row's id, status and confirmation date, and exits non-zero if the
guarantee is not in force. It never deletes a subscription: which of two
sign-ups is *the* consent record is a decision about people, and `--repair`
refuses to build the index while anything would have to go for it.

Worth running once after any update that touched migrations, and in particular
on an install that came from 1.2.1 or earlier through 1.6.1–1.6.3 — see the
1.6.4 entry in `CHANGELOG.md`.

### Every brand sends as itself

A mail belongs to a brand, so the transport and the sender it goes out with
belong to the brand too. Put them in `brands.settings.mail`:

```php
$brand->update(['settings' => ['mail' => [
    'from_address' => 'noreply@chorgesucht.de',
    'from_name'    => 'chorgesucht.de',   // defaults to the brand name
    'mailer'       => 'scaleway_chorgesucht',  // a mailer from config/mail.php
    'locale'       => 'de',               // the language its mail is written in
]]]);
```

Campaigns, single sends, CP test mails and the double opt-in confirmation all
go through that identity — the same one `config/mail.php` names, so the SMTP
credentials stay in the environment and never reach the database, a backup or a
CP export.

**Why the mailer and not just the From.** A relay that verifies sending domains
per account (Scaleway TEM, Postmark, SES) refuses — or silently replaces — a
From it does not own. Sending brand A's newsletter confirmation through the
account that only knows brand B is how a reader ends up with a confirmation for
one newsletter under a different company's name. The two values have to be
chosen together, which is why they live in one place and are resolved in one
place.

**Nothing changes for a single-brand install.** A brand with no
`settings.mail` — which is every brand until someone fills it in — sends with
`marketing.sending.mailer`, `marketing.from.*` and the application locale,
exactly as before.

**The brand's address beats a campaign's own From** (since 2.2.0; before that it
was the other way round). Fill in *From email* on a campaign and it applies
wherever the brand declares no address of its own — every single-brand install.
Where the brand does declare one, the campaign value is dropped and the reason
is written to the log. The address and the transport are one pair: only the
brand row knows which addresses the relay account behind that transport owns,
and a per-campaign address can be checked by nobody until the provider sees it,
at which point the fan-out has already started. *Reply-to* is per campaign and
untouched, which is the field that decides where an answer lands.

**A typo in `mailer` sends nothing, on purpose.** A name that `config/mail.php`
does not define is refused when the identity is resolved, before anything is
rendered or stamped. Falling back to the configured mailer would send the
brand's mail through somebody else's account, which is the failure this whole
section is about. Check the name against `config/mail.php` when you set it.

**Name both or neither.** A brand that fills in `from_name` or `mailer` and
leaves `from_address` empty sends nothing at all and says so in the log (since
2.2.0; 2.1.0 sent over the brand transport with the host-wide From). That pair
is what has to agree, and splitting it is the same failure with the halves
swapped. An address without a mailer is fine — that is the ordinary case for the
brand the global credentials belong to. Other keys under `settings.mail` do not
count as declaring a sender: a host that keeps, say, a base URL there is not
suddenly refused for a missing address.

A host that keeps sender identities somewhere else rebinds one contract:

```php
$this->app->bind(
    \Goldnead\Marketing\Contracts\SenderIdentityResolver::class,
    MyOwnResolver::class,   // resolve(?int $brandId): SenderIdentity
);
```

The rule itself lives in `goldnead/statamic-brand-context` since 1.8.0 —
`Goldnead\BrandContext\Sending\SenderIdentity` and friends — because which
address a brand sends under is a property of the brand, not of whichever addon
happens to be posting. The contract above stays in this namespace so that a host
can answer the question for marketing post alone.

**List handles are unique across all brands** in both drivers. The public
subscribe endpoint derives the brand from the list handle the form names — no
brand in the URL, no session, nothing for a visitor to get wrong — and that
only holds while a handle has exactly one owner. Creating a duplicate is
refused with a message naming the brand that holds it.

## Configuration highlights (`config/marketing.php`)

| Key | Default | Purpose |
| --- | --- | --- |
| `storage.driver` | `flat` | `flat` (YAML in `content/marketing/`) or `eloquent` |
| `sending.mailer` | app default | Laravel mailer for campaigns, and the fallback for brands that name none (see [Every brand sends as itself](#every-brand-sends-as-itself)) |
| `sending.messages_per_minute` | `0` | Throttle for ESP rate limits (0 = off) |
| `subscriptions.double_opt_in` | `true` | Default for new lists (per-list override) |
| `subscriptions.confirmation_throttle.enabled` | `true` | The per-RECIPIENT limit on confirmation mail (see [Sign-up is a public endpoint](#sign-up-is-a-public-endpoint)) |
| `subscriptions.confirmation_throttle.store` | app default | Which cache store counts. A store that cannot increment atomically — including the `file` default — is refused, and then nothing is sent |
| `subscriptions.confirmation_throttle.per_list` / `.per_list_window_minutes` | `1` / `60` | One confirmation per list per hour for one mailbox |
| `subscriptions.confirmation_throttle.per_mailbox` / `.per_mailbox_window_minutes` | `5` / `1440` | The ceiling across every list and brand at once |
| `subscriptions.confirmation_ttl_hours` | `168` | How long an unused confirmation link stays valid (0 = forever) |
| `subscriptions.confirm_requires_post` | `true` | Confirming needs a button press, so link scanners cannot consent for the reader |
| `unsubscribe.global_opt_out` | `false` | Also set LeadHub `do_not_contact` on unsubscribe |
| `tracking.opens` / `tracking.clicks` | `true` | Toggle tracking |
| `delivery.mail_headers` | `[]` | Per-message headers asking the provider not to rewrite links |
| `delivery.ignored_query_parameters` | 11 provider names | Parameters a click counter may append without breaking the signed redirect |
| `leadhub.tag_subscribers` | `true` | Tag contacts with `list:{handle}` |
| `frequency_cap.enabled` | `false` | Off until you turn it on — updating the package changes no send |
| `frequency_cap.max` / `.window_hours` | `3` / `168` | Three marketing mails per seven days |
| `frequency_cap.defer.*` | `1440` min, `3` tries | How long a capped message waits, and how often, before it is discarded and logged |
| `archive.enabled` | `true` | The archive routes; per-campaign visibility is still off by default |
| `archive.prefix` | `newsletter` | Path for the index, `feed.xml` and each campaign page |
| `archive.neutral_name` | `null` | Stands in for `{{ first_name }}` / `{{ name }}` on the web version (falls back to the translation) |

### Classifying mail from another addon

The classification is a contract, not a marketing internal — a cap is only as
good as its exceptions, and the addon doing the sending is not the one that
knows what a mail is for. Any package in the family can name a class and ask
before it sends:

```php
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;

if (app(FrequencyCap::class)->allows($email, MailClass::Marketing, $brandId)) {
    // …send…
    app(FrequencyCap::class)->record($email, MailClass::Marketing, $brandId, 'my-addon:weekly');
}
```

`allows()` is always true when the cap is off and always true for a class the
cap does not act on, so a caller never has to know the exceptions. Unknown or
absent reads as `marketing`: forgetting to classify costs a delay, never an
exemption nobody asked for. Counting is keyed on the normalized address, so
somebody on four lists is still one person with one budget.

The check falls **open** — a cap that cannot count says yes and logs it. That is
the deliberate opposite of `Goldnead\Suppression\Contracts\Gate`, which falls
closed and aborts the send: suppression is the only thing between a send and
somebody who said no, while the cap is between a send and somebody who has been
hearing from you a lot.

### Publishing an issue to the web archive

Open the campaign's report page and switch **Publish a public web version** on.
It takes effect once the campaign has actually been sent, and switching it off
removes the page on the next request — nothing caches the list. Under
multi-brand the index and the feed show whichever brand is current for the
request; a campaign page derives its brand from the handle, the same way the
subscribe endpoint derives one from a list handle.

### Sending through a provider that counts clicks

Brevo, Mailgun, Mailchimp and most others rewrite every `href` in the HTML part
onto a counter of their own and forward the reader with an extra parameter
attached. The click redirect this addon signs does not survive that on its own:
Laravel signs the whole query string, so one appended parameter is a 403 — the
reader never arrives and the click is not counted either.

`delivery.ignored_query_parameters` names the parameters that may be appended
without invalidating the signature. It ships with the eleven that real providers
add, and it will not ignore `url`, `expires` or `signature` however it is edited:
this route carries its destination in the query, so an ignorable `url` would be
an open redirect on your own domain rather than merely a weaker signature.

`delivery.mail_headers` is the other half — the per-message header that switches
the provider's own counter off, which most of them offer and Brevo does not. It
is empty by default; `config/marketing.php` has the verified table of names.

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

### Against a real MySQL server

```bash
vendor/bin/pest -c phpunit.mysql.xml
MARKETING_DRIVER=eloquent vendor/bin/pest -c phpunit.mysql.xml
```

Same tests, `DB_DRIVER=mysql`; point `DB_HOST` / `DB_PORT` / `DB_DATABASE` /
`DB_USERNAME` / `DB_PASSWORD` at a throwaway database, which the suite migrates
from scratch on every test.

SQLite is not a substitute for it. It has no InnoDB key-length limit, stores no
fixed column widths and has no per-character byte cost, so a green SQLite run
says nothing about whether MySQL can build this schema at all — the blind spot
that took `statamic-notifications` down on production. `tests/Unit/IndexKeyLengthTest.php`
covers that class of defect without needing a server: it compiles the addon's
own migration files through Laravel's MySQL grammar in pretend mode and measures
every index the way InnoDB would, including whether a unique covers a column
that may be NULL and therefore constrains nothing.

### Component tests (Vitest)

```bash
npm install
npm test               # or: npx vitest run   /   npx vitest  (watch)
```

The Control Panel is a Vue SPA, and until 1.6.1 nothing in this package could
execute a line of it. PHPUnit reaches the controller and the props it hands
over; `tests/Feature/CpValidationVisibilityTest.php` reads the .vue sources and
proves the error wiring is present. Neither can say whether a rejected form
actually shows the message — that sat between them, and a screenshot was the
only evidence there was.

Vitest closes that gap. It is deliberately narrow:

- **What belongs here:** logic inside a component — computed fallbacks, which
  operator a stored `false` or `0` has to survive, where an error is rendered,
  what a component is handed.
- **What does not:** navigation, saving, permissions end to end, anything
  crossing into PHP. Those are feature tests.

Setup notes, in case something fails at an import rather than at an assertion:

- Vitest reads the same `vite.config.js`. Under `VITEST` the Statamic Vite
  plugin is swapped for the plain Vue plugin, because the former rewrites
  `vue` to `window.Vue` — correct for the CP bundle, fatal in a test process.
- `@statamic/cms/ui` and `@statamic/cms/inertia` are re-export shims that
  destructure a `__STATAMIC__` global the CP installs at runtime.
  `tests/js/setup.js` installs it first and answers every requested name with
  a stub component that mirrors its attributes into the DOM, so a test can
  assert what a component was handed without pinning down CP markup that is
  not ours. It also installs `__` as a real global, because a `<script setup>`
  block calls the translator directly and Vue Test Utils' `mocks` only reach
  templates.

## License

Commercial license. See [LICENSE](LICENSE).
