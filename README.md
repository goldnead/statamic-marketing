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
- **Tracking**: open pixel and signed click redirects, each switchable off.
- **Campaign report** in five tabs — Overview, Delivery, Opens, Clicks,
  Unsubscribes — every one of which shows the people behind the number rather
  than the number alone. The overview adds the A/B variants and a timeline
  (scheduled, sending started, sent, first open, last activity) in which a
  station that did not happen is left out instead of shown empty; Delivery names
  the reason a message did not go out. Each tab exports the same selection as a
  CSV — same filter, same order, streamed. Every row links to the reader's
  LeadHub contact where one exists, and never creates one. The overview also
  carries the **activity curve**: opens and clicks over the time since the send,
  with the machine share drawn apart from the human one. See
  [The campaign report](#the-campaign-report).
- **Marketing dashboard** with two charts beside the totals: the open and click
  rate of the last twelve sent campaigns in send order, and sign-ups against
  sign-offs per week over twelve weeks. The rates are lifted out of
  `CampaignStats` unchanged rather than worked out a second time. See
  [The marketing dashboard](#the-marketing-dashboard).
- **Machine opens are named as such.** Apple Mail Privacy Protection loads the
  tracking pixel for every message it delivers, read or not, so an open rate on
  its own no longer says what it appears to. The existing counters keep counting
  everything; the human figures stand beside them under their own names. See
  [What an open means here](#what-an-open-means-here).
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
  `list:{handle}`. Hard bounces and complaints opt the contact out. Every mail
  the addon sends — sent, opened, preloaded, clicked, bounced, marked as spam —
  is written onto the recipient's timeline as well, so the question "what has
  this person had from us" is answerable on the contact. See
  [Every mail on the contact](#every-mail-on-the-contact).
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
- **Sequences** as their own screen (Marketing → Sequences): a trigger, a
  mailing list, and the mails as a list with the gap before each one. Saving a
  sequence writes the automation a hand-built series would be. See
  [Sequences](#sequences).

## Broadcasts and sequences

Kajabi and its peers separate "one mail to many" (a broadcast, with an A/B
test) from "a series, started by an event" (a sequence). This addon has both,
and neither is a second engine.

**A broadcast is a campaign.** It has been one since 1.0: a subject, a body, a
list and optionally a segment, a scheduled time, a sent time, and a report with
recipients, delivered, opened and clicked. The A/B split on the subject line is
`variant_subject`. There is no separate broadcast object because there is
nothing a broadcast would carry that the campaign does not.

### A/B test share

`ab_share` is the share of the audience a subject-line test runs on, in
percent. `0` — the default, and what every campaign that exists today has —
means the whole audience is split half and half between the two subjects. `10`
to `50` means "test on this share, then send the winner to the rest".

**The winner send is not built yet.** The column, the field on the campaign
editor and the validation (0, or 10 to 50, and only with a variant subject)
ship first so a campaign written now carries the answer. Until the winner send
lands, a campaign with `ab_share = 20` sends exactly like one with `0`: the
whole audience, split evenly, no winner picked. The field says so.

### Sequences

A sequence is a series of mails a person receives after an event — a purchase,
a completed funnel, a tag set on the contact. It lives under Marketing →
Sequences and is edited as a list: the trigger, the mailing list the consent
comes from, and the steps, each with an `et_templates` template, an optional
subject, and the gap measured from the previous step.

**A sequence is a view plus a generator, not a second scheduler.** Saving one
writes exactly the automation an editor would otherwise build by hand in
`goldnead/statamic-automations`: the trigger node, then for every step a
`delay` (when the step has a gap) and a `marketing.send_email` node in template
mode, wired in one line. The engine's `automation_scheduled_jobs` is the only
queue; the marketing send path with consent, suppression, opt-out and the
frequency cap is the only sender. The sequence adds neither.

What the generated automation looks like, and why:

- Node keys are positional — `trigger`, `mail_1`, `delay_2`, `mail_2`, … — so a
  second save rewrites the graph without moving a run that is asleep in
  `delay_2` off its node. Changing a template, a subject, a gap, or the order
  of the steps is a change of content for a run already under way. Setting a
  gap to zero is too: `delay_n` goes, `mail_n` stays, and anyone asleep in that
  gap is moved to just in front of the mail rather than being cancelled.
  **Removing a step is not**, and the editor is asked before it happens: a save
  that would take away a step somebody is waiting on is refused with the number
  of people it affects. Confirm it and those runs are ended right there — the
  wake-up call cancelled, the run closed as `cancelled` with the reason on it —
  instead of failing days later with `Cannot resume — node 'mail_3' not found
  in automation` in a log nobody on this side reads. Shortening a series people
  are currently in is a decision, not a detail.
- Sequences need automations on its **`database`** storage driver. On
  `flat_file` there is no automation row to point at and no way to ask who is
  waiting in a flow, so every guard above would silently read zero. The screens
  say so instead, and nothing is written.
- The trigger's re-entry rule is *once per person* unless the trigger config
  says otherwise. A series is something a person goes through once.
- Each mail node carries the subject explicitly: the step's own subject, or
  the template's subject read at save time. `marketing.send_email` in template
  mode takes its subject from the node and nothing else, so a template whose
  subject changes later reaches the automation on the next save of the
  sequence. A step with no subject anywhere is refused at save.
- The automation is `created_by = marketing.sequence:<handle>` and its
  description names the sequence. **It is marked, not locked**: the automations
  addon has no read-only flag for a flow, so the canvas can still edit it — and
  the next save of the sequence overwrites those edits. Both screens say so.
- Deleting a sequence **disables** its automation and keeps it. The runs are
  the record of what went to whom.

**Without `goldnead/statamic-automations`** a sequence can still be written and
is kept. The list and the editor show *Automations not installed — the sequence
does not run*, and nothing is sent. Install the addon, save the sequence once,
and it is written.

Permission: `manage marketing sequences` (reading the list is `view marketing`).

## Screenshots

| | |
| --- | --- |
| ![Marketing dashboard](screenshots/dashboard.png) | ![Mailing lists](screenshots/lists.png) |
| Engagement across recent campaigns, list growth by week, and where the audience stands | Lists with double-opt-in status and live subscriber counts |
| ![Campaign composer](screenshots/campaign-edit.png) | ![Campaign report](screenshots/campaign-report.png) |
| Antlers content, sender, scheduling, test send | Five tabs with the people behind each figure, and when the mail was actually read |

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

`marketing:release-stale-sends` runs alongside it, every five minutes. Sending
claims a message before it delivers, so that two workers cannot send the same
mail twice; this hands back anything whose worker died while holding the claim
(`marketing.sending.claim_lease_minutes`, 15 by default). A message that had
already been handed to the mail server is closed rather than re-sent, and the
reason is written to the log — a second copy is certain harm, a missed mail is
possible harm and can be looked into.

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

## The campaign report

The report opens on **Overview** and has four more tabs — **Delivery**,
**Opens**, **Clicks**, **Unsubscribes** — each of which lists the people behind
the figure rather than the figure alone. The queries are in
`src/Services/CampaignReport.php`. `CampaignStats` is deliberately untouched by
all of it: its numbers are what every earlier campaign was measured with, and
redefining one of them quietly would look like a collapse in engagement that
never happened. What is new stands beside the old figures under its own name.

- **Overview** — the figures, the A/B variants, and the campaign as a sequence:
  scheduled, sending started, sent, first open, last activity. A station that
  did not happen is left out; a row reading "Scheduled: —" only invites the
  reader to wonder what went wrong. "Sending started" is the only derived
  station (nothing records when a send began, so the audience snapshot stands in
  for it), and it is dropped rather than printed when it would land after the
  send did.
- **Delivery** — every recipient with status, time and counters, narrowable to
  one message status. Where a message did not go out, the reason is in the row.
  That column had been in the database from the start and was never shown. It
  appears only on a campaign where something actually failed; the CSV carries
  the field either way, because a column that comes and goes is worse in a file
  than an empty one.
- **Opens** — who, when first, how often, and how much of it was machine. Built
  on `first_opened_at` rather than on the open counter, so a reader whose client
  blocks images and who clicked is in the list.
- **Clicks** — one row per click: who, when, which link, plus the breakdown by
  link. The one tab that is not per person, because somebody who followed two
  links did two things.
- **Unsubscribes** — who and when.

Every row links to the reader's LeadHub contact **where one already exists**,
and never creates one; a report is a read. The link is resolved over the
normalised address rather than the subscription's contact uuid, which is only
filled once a subscription has been confirmed and synced. A Control Panel user
who may read marketing but not LeadHub's contacts gets no links at all, rather
than rows that answer 403 when clicked.

Each tab offers the same selection as a CSV (route `marketing.campaigns.export`,
one query string for both): same filter, same order, streamed in chunks so that
a campaign with fifty thousand recipients does not have to fit in memory. The
export sits behind `manage marketing campaigns` while reading the report needs
`view marketing` — taking a file of addresses off the server is a different
question from looking at a page. A cell that begins with `=`, `+`, `-`, `@`, tab
or carriage return is written with a leading apostrophe: the name fields come
from a public sign-up form, so a stranger chooses their content, and a
spreadsheet runs such a cell the moment the file is opened, on the machine of
the person who was allowed to export it.

### When it was read

The overview draws opens and clicks over the time since the send
(`CampaignReport::activity()`), with the machine share kept apart from the human
one all the way to the screen. The chart is hand-drawn out of CSS boxes: the
Control Panel's CSP allows no CDN script, and three pictures are not worth a
dependency the whole addon would carry.

The grid follows the campaign. While the activity fits inside about three days
it is hourly, past that daily, and ninety buckets at the outside. Which of the
two is decided from the ninety-fifth percentile of the events, not from the last
of them: opening a mail again three weeks on is entirely ordinary, and an `n` of
one must not turn three days of reading into three bars out of ninety. Anything
that lands past the end of the axis is counted and said in words underneath
rather than dropped. The axis starts at the send and not at the first event —
"two hours until anybody looked" is a fact about the campaign, and an axis
beginning at the first open hides it. Empty buckets in between are noughts.

Four things decide whether the picture is read correctly:

- **The axis measures against the tallest human bucket, not the tallest
  bucket.** Apple's proxy fetches the pixel for roughly half of a campaign
  inside a single hour, while reading spreads over ten hours and more. Sharing
  one axis with that, the tallest human hour lands at a tenth of the track and a
  typical one at a fiftieth — three to five pixels, with hour four and hour
  seven a rounding error apart. So human opens and clicks set the ceiling, the
  preload bar runs into it, and **that is the statement**: it does not fit here.
  The figure it really has is written out in the scale line beside the chart.
- **The bars count events; the tiles above them count messages.** "Opens in
  total" is the number of messages with at least one open, so somebody who
  opened the mail five times is a one there and a five here. Both are correct
  and they are not the same question; a note under the chart says so.
- **A campaign sent before 2026-08-15 cannot be read for its split.** That is
  the day the `machine` column arrived, and the migration gives existing rows
  `false` on purpose — no figure moved under anybody's feet on the day the
  update ran. For the tiles that is the right trade; for a chart whose whole
  subject is the difference between the two colours it is not, so the screen
  says it. It only says it where not one preload has been recorded for that
  campaign: a campaign sent shortly before the migration keeps collecting
  flagged opens afterwards, and a warning printed above an orange bar would be
  worse than no warning at all.
- **A campaign nobody has opened yet gets a sentence**, not an axis with nothing
  on it.

Every column is announced for screen readers as a sentence of its own, and the
chart carries a one-sentence summary of the whole — the bars themselves hold no
text.

### What an open means here

Apple's Mail Privacy Protection fetches the tracking pixel for **every message
it delivers**, shortly after delivery, whether or not anybody looks, and then
caches the image. Security gateways such as Mimecast or Proofpoint do the same.
Taken at face value, those fetches report that somebody read a mail nobody
opened, and say nothing about the reading that came afterwards.

`src/Support/MachineOpen.php` tells the two apart as far as anything can. It is
a **heuristic, not a detection**: MPP is built to be indistinguishable, so the
class is wrong sometimes, in both directions. The direction of its doubt is the
decision — **an unknown client counts as a person.** Filing a real reader as a
machine is the error that would make the whole thing worse than not trying at
all. Gmail's `GoogleImageProxy` counts as a person on purpose: Gmail proxies the
image when the message is actually opened, so its fetch is a reading.

- **The existing counters are unchanged.** `opens` still counts everything, so
  every comparison with an earlier campaign stays valid. The human figures sit
  next to them with names of their own: *By a person*, *Open rate (people)*,
  *Machine only*.
- **A click counts as a person.** Under MPP the only open on record is the
  machine's, so counting opens alone reported "read by nobody" for a campaign
  somebody had clicked through.
- **Only the answer is stored**: the boolean `machine` on
  `marketing_message_events`. No user agent, no IP address — see
  [Personal data](#personal-data).
- A second event, `MessageOpenedByHuman`, fires on the first open a person is
  believed to have made. Behind a scanning mailbox the first open is the
  machine's, and `MessageOpened` has fired by then and does not fire again;
  without the second event the contact would read "preloaded" forever and never
  once say the person had read it.

## The marketing dashboard

Under the audience totals and the five most recent campaigns, two charts drawn
by the same component as the activity curve.

**Engagement across recent campaigns** — the open and click rate of the last
twelve campaigns that actually went out, oldest on the left, because a trend is
read left to right. Drafts and scheduled campaigns are left out: an open rate on
a campaign that was never sent is a nought pretending to be a result. The rates
are lifted straight out of `CampaignStats`, the same numbers the campaign's own
report prints, rather than a second calculation that happens to agree today —
two different open rates in one addon is a defect this repo has shipped once
already, and this is exactly where it starts. The bars are scaled to the largest
rate in the row rather than to a fixed hundred, since a three-percent click rate
against a full-height axis is a line nobody can compare; the top of the axis is
therefore stated above the chart. Two sentences appear when they apply: that a
trend needs at least two sent campaigns, and that the newest bar is early
because the campaign went out less than 48 hours ago and is still collecting its
opens.

**List growth** — sign-ups against sign-offs per week over twelve weeks
(`SubscriptionGrowth`), weeks starting on Monday whatever the CP language is set
to. A week nobody joined is an empty track rather than a missing column, so a
quiet month cannot look like a busy one. What it counts, and the caveat that
comes with it:

- Sign-ups from `subscribed_at`, falling back to `created_at` where that is
  null. `subscribed_at` is written when the form is submitted, so a week counts
  sign-**ups**, not confirmed subscribers. Counting `confirmed_at` instead would
  look stricter and be worse: it is null on every row that arrived by import or
  predates the column, and those weeks would silently read nought.
- Sign-offs from `unsubscribed_at`, which every route out writes — link,
  preference centre, bounce handling.
- **It is the list as the database stands today, not an immutable ledger.**
  `unsubscribed_at` is cleared when the same address subscribes again, so
  somebody who left in week two and came back in week five is gone from week two
  and appears only with the new sign-up. The page carries that sentence under
  the chart. Unsubscribe *events* are never rewritten, but they are worse for
  this question: one is only recorded where the unsubscribe carried a message,
  which would drop every sign-off made in the preference centre. Complete beats
  immutable here.

### One lookup for the whole page

`CampaignStats::forCampaigns()` answers for any number of campaigns in **two**
queries. The loop it replaces asked `forCampaign()` per row, about six queries
each: thirty for the five-row table, and the twelve campaigns of the engagement
chart would have added seventy-odd more to a single page. Both paths build their
result through the same method, so "open rate" is defined once in the addon, and
a test holds the two against each other. `DashboardQueryCountTest` measures that
the count does not move between two campaigns and twelve — the page still asks
once per mailing list, which is what that test is named for.

## Every mail on the contact

Sent, opened, preloaded, clicked, bounced, marked as spam: each writes one entry
onto the recipient's LeadHub timeline, with subject, campaign and list as
readable lines rather than a payload dump
(`src/Integrations/Leadhub/TimelineRecorder.php`). The facts were always
recorded — in `marketing_messages` and `marketing_message_events`, keyed by
message, which is where nobody looking at a person would find them.

Two properties carry this, and neither of them is about the good case:

- **A tracking pixel never creates a contact.** For an address with no LeadHub
  contact nothing is written and nothing is created. Somebody who signed up and
  never confirmed has no contact on purpose, and an opened confirmation mail is
  not consent to be filed.
- **Nothing on this path turns a delivered mail into a failure.** It hangs off
  the send path and off two public tracking endpoints, so it catches everything
  and logs it throttled per kind of failure — a send to five thousand people
  with a CRM that is down would otherwise put five thousand identical lines in
  the log and bury the one that matters.

Switchable off with `marketing.timeline.enabled`, narrowable to single kinds
with `marketing.timeline.types`. Fifty thousand recipients and one row per open
on every contact is a legitimate thing not to want.

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
| `timeline.enabled` | `true` | Write every mail onto the recipient's LeadHub timeline (see [Every mail on the contact](#every-mail-on-the-contact)). Nothing is written for an address with no contact |
| `timeline.types` | `[]` | Which of the six kinds are written; empty means all of them. An install sending to fifty thousand people may not want a row per open on every contact. Constants on `Integrations\Leadhub\TimelineRecorder` |
| `delivery.mail_headers` | `[]` | Per-message headers asking the provider not to rewrite links |
| `delivery.ignored_query_parameters` | 11 provider names | Parameters a click counter may append without breaking the signed redirect |
| `leadhub.tag_subscribers` | `true` | Tag contacts with `list:{handle}` |
| `frequency_cap.enabled` | `false` | Off until you turn it on — updating the package changes no send |
| `frequency_cap.max` / `.window_hours` | `3` / `168` | Three marketing mails per seven days |
| `frequency_cap.defer.*` | `1440` min, `3` tries | How long a capped message waits, and how often, before it is discarded and logged |
| `archive.enabled` | `false` (`MARKETING_ARCHIVE`) | The archive routes. Off means they are **not registered at all**, so no route exists to link to |
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

The archive ships **off**: `MARKETING_ARCHIVE` defaults to `false`, and while it
is off the archive routes are not registered. Set `MARKETING_ARCHIVE=true` first
— the switch below only appears once they exist.

Then open the campaign's report page and switch **Publish a public web version**
on.
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

## Personal data

The addon stores personal data in your own application's database, in four
tables of its own:

| Table | What is in it |
| --- | --- |
| `marketing_subscriptions` | The consent record: address (as given and normalised), first and last name, list, status, source, the unsubscribe and confirmation tokens, whatever the sign-up path recorded as `meta`, and the subscription/confirmation/unsubscription timestamps |
| `marketing_messages` | One row per mail: recipient address, status, the reason a send failed, open and click counters and their first/last timestamps |
| `marketing_message_events` | One row per open, click, unsubscribe, bounce or complaint: the kind, the `machine` verdict on an open, the clicked target, and for an inbound ESP feedback event what the provider sent |
| `marketing_mail_log` | The frequency cap's bookkeeping: normalised address, mail class, brand, reference and the time of each mail after it was delivered. Written whether or not the cap is switched on |

Outside these four: one entry per mail on the recipient's **LeadHub contact
timeline** — LeadHub's table, not this addon's, and only ever for a contact that
already exists (see [Every mail on the contact](#every-mail-on-the-contact)).
Suppression records belong to `statamic-suppression` and are documented there.

**What is not stored: no IP address and no user agent.** The fetching client of
an open is read while the request is in hand, answered with the one boolean
`machine`, and dropped. An IP is personal data and a stored user agent is a
fingerprint, and neither is needed once the question has been answered.

**Deleting it.** Deleting a subscription in the Control Panel (list → subscriber
→ delete) takes its messages and their events with it: both foreign keys cascade
on delete. The contact itself and its timeline are LeadHub's, and go when the
contact does. `marketing_mail_log` hangs off no subscription and is not cleared
with one; it holds an address, a class and a time, and rows older than
`frequency_cap.window_hours` are of no further use to anything here.

## Testing

```bash
composer install
vendor/bin/pest                     # flat driver (default)
MARKETING_DRIVER=eloquent vendor/bin/pest   # eloquent driver
vendor/bin/pest --testsuite=ShippedDefaults # the configuration the addon ships

# Live cross-addon integration suite (installs automations + webhook-manager
# into a throwaway copy; point the *_PATH vars at local checkouts):
AUTOMATIONS_PATH=../statamic-automations \
WEBHOOK_MANAGER_PATH=../statamic-webhook-manager \
scripts/test-siblings.sh
```

CI note: `goldnead/statamic-leadhub` is a private sibling repo, so the GitHub
Actions workflows need a `SIBLING_REPOS_TOKEN` repository secret (a PAT with
read access to it) to check it out next to this package.

### The suite that runs the shipped configuration

`tests/TestCase.php` switches the archive **on** for the whole suite, so that
the archive can be tested at all. The price of that one line is that no test
ever entered the configuration the addon actually ships with — and the campaign
screen built `route('marketing.archive.show')` unconditionally, a route that is
only registered while the archive is enabled. Every default install answered 500
on that screen, through a green suite, for as long as the archive has existed.

`tests/ShippedDefaults/` is the suite that meets the addon as it ships. Setting
the config inside a test body is not enough: routes are registered while the
application boots, so by the time a test runs, `Route::has()` has already been
decided. The switch has to be thrown before boot, which is what
`tests/ArchiveOffTestCase.php` is for. Anything that behaves differently under a
shipped default than under the test defaults belongs in this suite.

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
