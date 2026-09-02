# Sequences

A welcome series, a follow-up, a drip. What runs is always an automation in
`goldnead/statamic-automations`; what this addon contributes is the one node
that automation cannot write for itself, and — since 2.20.0 — a **Sequences**
screen that writes that automation for you (Marketing → Sequences, see the
README section "Broadcasts and sequences"). The screen is a list over the
graph: trigger, list, steps with their gaps. Saving it produces the exact
node chain described on this page, on the node described on this page. This
page explains the node; the rest holds whether the chain was built on the
canvas or by the screen.

## Why the node lives here

`automations` already does the timing a sequence needs — delays, wait-until
windows, branches, brands, throttles. What it cannot do is send a *marketing*
mail, because everything that makes one different from an ordinary mail is this
addon's domain: which list carries the consent, whether the address is
suppressed, whether the person has opted out, whether they have already had
their three mails this week.

So `marketing.send_email` is registered from this side, by
`Integrations\Automations\AutomationsBridge`. The orchestrator never learns what
a newsletter is.

### Not `automations`' own `send_email`

That node stays domain-neutral: an address, a subject, a body, `Mail::raw()`. It
asks nobody whether the recipient wants marketing mail, because it is also how a
site sends a password reset. It is unchanged.

### Not `ThrottleNode`, either

`ThrottleNode` throttles **one flow**: at most one run per key per window. The
frequency cap counts a **person's** marketing mail across every flow, every
campaign and every broadcast in the same brand. Two sequences that each throttle
themselves correctly still add up to six mails a week for somebody who is in
both. Only a node on the marketing send path can see that.

## Two ways to write the mail

Sites write their sequence mails in one of two places, and the node takes
either. **Exactly one of `Campaign` and `Email template` is set.** Both is two
different answers to "what is this mail"; neither is no answer at all, and both
are configuration errors that a *test run* reports — an editor finds out a node
is broken by pressing Test, not three days later when the first person reaches
that step.

### Campaign mode

The mail is a campaign, left in draft. It carries its own subject, content,
layout, list and classification.

### Template mode

The mail is a managed email template from `goldnead/statamic-email-templates`
(`et_templates`), addressed to one person. This is the shape the domain-neutral
`send_email` node in `automations` uses — `to`, `subject`, `template` — so a
site that already writes its welcome mails that way can move them onto this node
unchanged, which is the whole point: until 1.12.0 they could not, and every one
of them was going out through the neutral node without consent, suppression,
opt-out or the cap ever being asked.

Two things are required here that campaign mode does not need:

- **Mailing list.** Not optional. A campaign carries its own list and a template
  carries none, so without this field there is nothing to prove the recipient
  ever agreed to be mailed — and a marketing mail may not go out on that basis.
  The node refuses rather than sending unchecked.
- **Subject.** A campaign brings one; a template is a layout. The node's subject
  is the mail's subject, and a subject stored on the template entry itself is
  not consulted.

The template is the mail. There is no content to inject: if the template prints
`{{ content }}`, it renders nothing there. Marketing's own merge variables
(`{{ first_name }}`, `{{ unsubscribe_url }}`, `{{ subject }}`, …) resolve inside
it exactly as they do in a campaign. The person is also available under
`{{ subscriber.first_name }}`, `{{ subscriber.email }}` and so on, which is how
an automation's own node config names them — so the same template body works
whether the node that sends it is `marketing.send_email` or the domain-neutral
`send_email`. Both spellings are derived from one set of values and cannot
disagree. Tracking, the open pixel and the
`List-Unsubscribe` header are applied the same way. A template reference that
resolves to nothing is a **failure**, not a fallback layout — for a campaign the
built-in layout is a reasonable frame around real content, but here it would
deliver an empty mail under a subject the reader recognises.

Without the email-templates package installed, template mode still resolves
against marketing's own template repository. A slug that answers to neither
fails with a message naming the missing package, rather than a fatal error.

## The shipped template

`Newsletter Welcome Series` in the automations template catalog builds the graph
for you: a *Subscriber Confirmed* trigger set to enroll each person once, a
mail, three days, a second mail. Both mails are `marketing.send_email` in
campaign mode with the **campaign left empty**, because the catalog cannot name
a campaign that does not exist in your site yet. Write the two mails as
campaigns, leave them in draft, pick them on the two nodes, then enable.

Until 2.7.1 that template used the neutral `send_email` and was therefore an
example of the exact thing this page warns about. It no longer is; nothing else
in the catalog sends to a subscriber through the neutral node.

## Setting one up

1. Write each mail — as an ordinary **campaign** left in draft, or as an
   **et_template**. A campaign is the content of a step; a sequence never queues
   it to the list.
2. Build the flow in Automations: a trigger, then `Send Marketing Email`, then
   `Delay`, then the next one.
3. Set the trigger's **Re-entry** rule. A welcome series wants *Ignore — only
   ever once per contact*; the default is *Enroll again every time*.

The node's fields:

| Field | Meaning |
|---|---|
| **Campaign** | Campaign mode. The campaign whose subject, content and template make up this mail. |
| **Email template** | Template mode. The `et_templates` slug to send instead. |
| **Subject** | Template mode only, and required there. Tokenable. |
| **Mailing list** | Where consent comes from. Campaign mode: empty uses the campaign's own list. Template mode: required. |
| **Recipient** | Empty uses the address the run is already about (`{{ subscriber.email }}`, `{{ contact.email }}`, `{{ email }}`). |
| **Classification** | Template mode only: `marketing` (capped) / `transactional` / `digest` / `reminder`. Defaults to `marketing`. Campaign mode ignores it and takes the campaign's own. |

## What the node actually does

`Sending\SingleSend` is the campaign send path with the fan-out removed and all
four gates kept, asked in the order the send path has always asked them:

1. **Consent.** A subscribed subscription on the configured list, or nothing is
   sent — not even to an address the flow otherwise knows perfectly well.
2. **Suppression.** The hard no, and the only gate that fails *closed*: a check
   that cannot be answered blocks the send. Not knowing is not permission.
3. **Opt-out.** LeadHub's `do_not_contact`, which is what the preference centre
   and an editor's manual opt-out both write. Also fail-closed.
4. **Frequency cap.** Last, because it is the only one that says "later" rather
   than "no", and there is no point deferring a mail to an address that may
   never receive it at all.

The classification in campaign mode is the campaign's own `mail_class`; in
template mode it is the node's **Classification** field. Both default to
`marketing` — so a mail nobody classified is capped rather than exempt — and a
mail declared `digest` or `reminder` keeps its exemption here exactly as it does
on the broadcast path.

## What happens when a gate says no

| Outcome | The run |
|---|---|
| **Blocked** (not subscribed, suppressed, opted out) | **Stops.** Not "skip this mail and carry on": every later step of a marketing sequence is more marketing mail, and continuing past somebody who may not be mailed is exactly what consent forbids. The reason is in the run log. |
| **Capped** | **Pauses and asks again later** — the same `frequency_cap.defer` budget the campaign path spends, so a reader is held back for the same length of time whether the mail came from a broadcast or from a sequence. |
| **Out of deferrals** | Sends nothing, **continues** the flow, and writes a warning naming the recipient and the campaign. Silent discarding is the version where somebody asks in three months why the third mail never arrived and nobody can answer. |

## The mail is a real message

Every send writes a `marketing_messages` row, so opens, clicks, bounces, the
unsubscribe link, the `List-Unsubscribe` header and the ESP feedback loop all
work exactly as they do for a broadcast, and the mail appears in the recipient's
own history.

A **template send has no campaign**, and its row says so: `campaign_handle` is
`NULL` and `template_handle` holds the slug. That is the honest encoding and
also the useful one. Every campaign report is a `where campaign_handle = ?`,
which never matches `NULL` on any engine, so a template mail stays out of numbers
it was never part of — without a single one of those queries being changed. A
placeholder handle would have been included in all of them as a campaign that
does not exist. `template_handle` is what keeps the row self-describing: "which
mail was this" is the first question a bounce, a complaint or a support request
asks, and it has to be answerable from the row alone.

The campaign itself is **never marked sent**. It is the content of a step, not a
broadcast that happened — and a campaign flipped to `sent` would drop out of
every later enrollment and, with the archive flag on, appear on the open web.

The same person can receive the same sequence campaign twice, which is the
deliberate difference from a broadcast: somebody enrolled in a welcome series a
second time gets the welcome series a second time. Whether that should happen at
all is the trigger's *Re-entry* rule, not this node's business.

## The mail list

Because the node declares itself a mail step (`mailStep()` / `mailSummary()`),
an automation built this way can be read as a list of its mails with the gap
before each one, and — while the flow is a straight line — edited from that list.
That view lives in `automations`; see its `docs/sequences.md`.
