# Sequences

A welcome series, a follow-up, a drip. There is no sequence object in this addon
and there is not going to be one: a sequence is an automation in
`goldnead/statamic-automations`, and what this addon contributes is the one node
that automation cannot write for itself.

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

## Setting one up

1. Write each mail as an ordinary **campaign** and leave it in draft. The
   campaign is the content of a step; a sequence never queues it to the list.
2. Build the flow in Automations: a trigger, then `Send Marketing Email`, then
   `Delay`, then the next one.
3. Set the trigger's **Re-entry** rule. A welcome series wants *Ignore — only
   ever once per contact*; the default is *Enroll again every time*.

The node's fields:

| Field | Meaning |
|---|---|
| **Campaign** | The campaign whose subject, content and template make up this mail. |
| **Mailing list** | Where consent comes from. Empty uses the campaign's own list. |
| **Recipient** | Empty uses the address the run is already about (`{{ subscriber.email }}`, `{{ contact.email }}`, `{{ email }}`). |

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

The classification is the campaign's own `mail_class`, which defaults to
`marketing` — so a mail nobody classified is capped rather than exempt, and a
campaign declared `digest` or `reminder` keeps its exemption here exactly as it
does on the broadcast path.

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
