# Changelog

## 1.8.1 — 2026-07-30

### Fixed — the one page that could hand back what the gate had just taken away

1.8.0 put `goldnead/statamic-suppression` in front of every path that puts a mail on the wire. The
preference page is not one of those paths, which is why it was not in the list, and the reasoning was
sound as far as it went. It is also the only surface in this addon that **writes consent back** — and
it was still asking the older, narrower question: `do_not_contact` on the LeadHub contact, and the
subscription's own `bounced`/`complained` status. Nothing asked the table the gate reads.

**What was possible while that was true.** Take an address that hard-bounces or files a spam complaint
after 1.8.0. The provider event is recorded in `suppressions` and nowhere else: it does not set
`do_not_contact`, and it does not rewrite this addon's subscription row unless the addon's own ESP
ingress happened to see the same event. The unsubscribe token in every mail that address was ever sent
still resolved. Opening it produced a page with every row switchable, ticking them all was accepted,
and the addon wrote fresh consent records — `reason: preference_center`,
`consent_proof: unsubscribe_token`, into the LeadHub timeline — for an address that was blocked at the
time it did so. A subscription that had been ended came back as `subscribed`; a list the person had
never been on could be created outright.

Measured on the QA hub against v1.8.0 before this release was written, not reasoned about: an address
carrying one active `complaint` row in `suppressions` (brand-scoped, `do_not_contact = false`, both
subscription rows still `subscribed`) opened its preference page and got **13 lists, none of them
disabled**. Removing nothing and simply ticking every box returned **13 subscriptions where there had
been 2** — eleven of them to lists the address had never been on — with no refusal and not one error
message on the page.

No mail went out. The gate held at all four send paths, so the block did its job at the door. What was
damaged is the record and what happens after it: a complainant carries a consent trail dated *after*
their complaint, and the day somebody legitimately releases a suppression — a mailbox restored, a
bounce that turned out to be an outage — that address is subscribed to lists it never asked for and
starts receiving them. And the token is not a private thing. It is repeated in every mail this brand
sends and has passed through mail clients, forwarding rules, scanners and link checkers. "Only the
person could have done it" was never the claim.

The rule this violated is the one 1.7.0 wrote down as the difference between a feature and a hole:
**the token may end consent, and may restore consent the person themselves ended, but may never lift a
block.** For one release that sentence was untrue whenever the block lived only in the suppression
table — which, since 1.8.0, is where provider events go.

### Changed — the page asks what the send paths ask, per row and in one brand

`SubscriptionPreferences` now reads **both** signals and treats either one as a block: `do_not_contact`
on the contact, and `Goldnead\Suppression\Contracts\Gate`. Neither replaces the other. An editor's
opt-out in the CRM still lands only on the contact; a provider bounce lands only in the table.

Two details that are not incidental:

**One question per row, not one for the page.** The rows of a preference centre do not all carry the
same mailbox. A person who holds a second list under a second address is found through the same
`contact_uuid`, and that row would be mailed at *its* address — so a single question asked for the
token's address would answer for the wrong mailbox on every other row. It is still one query;
`suppressedAmong()` takes the batch.

**One brand, and it is the page's own.** No brand is passed, so the gate resolves the current one,
which `SetBrandFromRouteValue` derived from this very token — the same brand whose lists are on
screen. That is what makes decision D1 land per row rather than as a blanket: a hard bounce is stored
globally and blocks here, because the mailbox is gone for everyone; a complaint stays in the brand
that received it and must not shut a page belonging to a brand the person never objected to. Asking
"blocked anywhere" would leak one brand's relationship into another; asking only this addon's own
state is what shipped in 1.8.0.

**A gate that cannot answer blocks everything.** "The query failed" and "nobody is suppressed" are not
the same statement. Catching `SuppressionCheckFailed` here is not carrying on — it *is* the closed
answer, drawn as a page whose rows are all un-switchable. It costs an unsubscribe during the outage,
which is the cheap side of the trade: every send path fails closed on the same fault, so there is
nothing going out to unsubscribe from.

The refusal stays in the service and not in the template, for the reason it always was: a disabled
checkbox is a suggestion, and a crafted POST is not. The test that removes `disabled` in the browser
and submits now runs against a block that exists **only** in the suppression table.

### Fixed — a test whose premise leadhub 1.11.0 retired

`PreferenceCenterBrandTest > it shares one contact across the brands` was red on both drivers when
1.8.0 was tagged, and it was not the gate's doing. It asserted that one address in two brands is one
LeadHub contact, so that the identity reached across brands on its own and the brand scope was the
only thing holding it. `statamic-leadhub` v1.11.0 made the flat contact store brand-isolated; it is
now two contacts. The premise is gone, and its going is an improvement.

Rewritten to the case that still proves something. The subscriptions have not changed: both brands
hold a row carrying the same address, and `subscriptionsOfThePerson()` matches on `email_normalized`
as well as on the contact, so `marketing_subscriptions` remains a second route to the person that does
not pass through LeadHub at all. What stops it surfacing is that the person's rows are keyed by list
handle, and **a list handle has exactly one owner across the whole install** — the flat store refuses
a handle another brand holds, and `2026_07_28_000001_restore_global_unique_on_list_handles` enforces
the same thing in the schema. Brand B's membership has no row of brand A's to land on, whatever the
identity match returns. The test now pins that, so the day handles become unique per brand instead, it
fails here and names this page as the consequence to re-check.

### Notes

- Suite: **202 passed, 7 skipped (756 assertions)** on the flat driver, **201 passed, 8 skipped (754)**
  on eloquent, plus **24** Vitest. Green on both drivers, and 1.8.0's known red is gone: +3 new cases
  and +1 that used to fail. The skips are the two sibling-integration files (those addons are not
  installed in this bed) and one flat-only repository case; they are unchanged from 1.8.0.
- Nothing under `resources/views/` changed, so the committed CP bundle is untouched. `npm run build`
  and `npm run build:check` were run anyway and report `FRESH` — the 1.7.0 lesson was that a Blade
  edit is enough to move it, not that a PHP edit is safe to assume about.
- `SubscriptionPreferences::__construct()` takes one more argument. It is container-resolved in every
  code path; only a test building it by hand needs updating.

## 1.8.0 — 2026-07-30

### Added — every send path asks one question, and it is not this addon's question any more

This addon could put a mail on the wire from four places. One of them checked whether the address was
allowed to receive it, in its own way; the other three did not check at all.

- `StartCampaignJob` asked LeadHub's `do_not_contact`, and failed closed when it could not resolve a
  contact — good, and the only one.
- `SendMessageJob` checked `isSubscribed()` and nothing else, so an address blocked *during* a long
  campaign was mailed by a queue that had stopped listening.
- `SubscriptionService::sendConfirmationMail()` sent a double opt-in mail to whatever a public form
  had been given. A blocked address that typed itself back in got mail from us.
- `CampaignSender::sendTest()` sent to any address an editor typed.

All four now go through `goldnead/statamic-suppression`, a new dependency at `^1.0`.

### Changed — the suppression list is a package, not a folder here

**1.7.0's changelog said, in bold, that the preference page reads the state this addon already keeps
"and not a suppression list of its own … there is no second list to drift out of step with the
first."** That sentence was right about the danger and this release does not walk it back — but it is
worth saying plainly what changed, because the shape does now include a list.

What made a second list the wrong answer *inside this addon* is what makes it the right answer
underneath it. A hard bounce is a property of the **mailbox**. It bounces identically whoever sends,
and it damages a sending reputation shared by everything in the application. If the list lived here,
`statamic-notifications` would still write to that mailbox with its immediate mail and its weekly
digest — same application, same reputation, same dead address — and the block would depend on which
addon happened to be sending. That is not one list drifting from another; it is one addon knowing
something and the rest of the install not being told.

So the layer sits underneath, beside `statamic-brand-context` and `statamic-identity-contracts`, and
this addon is a consumer of it rather than its owner. `do_not_contact` stays exactly where it was and
is still read: the gate is an additional signal, not a replacement, and nothing about LeadHub's
opt-out changed.

What did *not* move: the ESP ingress and the Control Panel surface. Those have one feeder and no
second consumer, so relocating them would have bought nothing and cost a dependency.

### Added — fail-closed at the audience, and the reason it is not the segment resolver's rule

`StartCampaignJob` asks once per chunk rather than once per subscriber, and a suppressed address never
becomes a `Message` row at all — a blocked address must not enter a send, not merely fail to leave
one. That also removes the N+1 the old per-subscriber contact lookup carried.

When the gate cannot answer, the campaign is returned to draft and the exception is re-raised. It does
not fall through to "nobody is suppressed".

Twelve lines above it, `resolveSegmentMemberIds()` does the exact opposite: a segment it cannot
resolve is ignored and the campaign goes to the whole list. Both are correct, and the reason is in a
comment beside the catch so the next person to read them does not tidy one into the other. A segment
*narrows* an audience — losing it can only send to more people who already said yes. Suppression is
the only thing standing between the send and an address that said no.

`SendMessageJob` fails a single message rather than the run, because one unreachable read must not
abort a campaign that is already half delivered. `sendTest()` is the one gate that speaks: it throws a
validation error naming the address, because an editor waiting at the screen for a test mail that
silently never arrives learns that the button is broken.

### Added — the backfill, while there is nothing to backfill

`2026_07_31_000001_backfill_suppressions_from_marketing_state` moves what this addon already knew into
the table that can be asked about it: `status = bounced` → `hard_bounce`, global; `status = complained`
→ `complaint`, that row's own brand; `leadhub_contacts.do_not_contact` → `manual`, brand-scoped.

`status = unsubscribed` is **left alone**, and that is the decision in this migration rather than an
omission from it. A per-list unsubscribe is a scoped withdrawal of consent for that list, and
`marketing.unsubscribe.global_opt_out` already defaults to `false` — somebody decided deliberately
that a list unsubscribe is not a global opt-out. Promoting those rows would reverse that decision
inside a migration nobody reads, and destroy legitimate subscriptions on the brand's other lists.

It is idempotent, it never overrules a suppression somebody already released (a released row carries a
name, a date and a stated reason; a backfill does not get to overturn that), and its `down()` removes
only the rows it wrote. It runs today against zero rows, which is the whole argument for writing it
now instead of after a second brand's mail is flowing through the same system.

### Notes

- Suite: **198 passed (733 assertions)** on the flat driver, **197 passed (731)** on eloquent,
  including 11 new gate cases and 5 new migration cases.
- One test fails on both drivers, and it is not this release's:
  `PreferenceCenterBrandTest > it shares one contact across the brands` asserts that one address in
  two brands carries one LeadHub contact. `statamic-leadhub` v1.11.0, released the same day, made the
  flat contact store brand-isolated — so it now carries two. Verified pre-existing by running the
  v1.7.1 tree against this same vendor directory. It belongs to LeadHub's release, not to the gate.
- The new migration is guarded: an install without the suppression tables meets a no-op rather than a
  crash. A migration that dies halfway leaves the addon half-installed, which is worse than a backfill
  that does nothing.
- It is dated `2026_07_31` rather than `2026_07_30` on purpose. Laravel sorts every loaded migration by
  filename across all registered paths, and `2026_07_30_000001_backfill_…` sorts *before*
  `2026_07_30_000001_create_suppressions_table` — "backfill" < "create". The first version of this
  release did exactly that on a real install: the backfill ran first, met its own guard, and did
  nothing, silently. `tests/Migrations/SuppressionBackfillTest.php` now sorts both directories together
  the way the framework does, because the migration bed installs the suppression package separately and
  first, and a bed that agrees with the code is not measuring it.
- `SendMessageJob::handle()` and `StartCampaignJob::handle()` take one more injected argument. Both are
  resolved by the container in every real code path; only a test calling `handle()` by hand needs
  updating.

## 1.7.1 — 2026-07-30

### Fixed — 1.7.0 shipped a control-panel bundle that did not match its source

No behaviour changed and nothing in `src/` was touched. `resources/dist/build` is rebuilt and re-committed, because 1.7.0's copy was already stale on the day it was tagged.

**How a page with no JavaScript on it moved the CP bundle.** `resources/css/cp.css` declares `@source "../views/**/*.blade.php"`, so Tailwind scans every Blade file in the addon for class-name candidates — including the new public preference partial, which has nothing to do with the control panel. That partial contains `<input type="hidden" name="action" …>`, the scanner reads the bare word `hidden` as a candidate, and the compiled stylesheet gains `.hidden{display:none}`. One utility, twenty-one bytes, nothing in the CP uses it.

The size of the change is not the point. **A template edit is enough to change the committed bundle**, and the assumption behind "I did not touch Vue, so I do not need to rebuild" is simply false in this package. `scripts/check-dist-fresh.sh` exists because webhook-manager once shipped a rebuilt source with an un-rebuilt bundle and browsers answered `vue is not defined`; it did its job here and reported `STALE` on a tree that was otherwise fully green across both drivers and Vitest.

Recorded so the next release does not re-learn it: **run `npm run build:check` after any change under `resources/views/`, not only after touching `resources/js/`.**

## 1.7.0 — 2026-07-30

### Added — a preference page, so an unsubscribe link is a choice instead of a door

**What was already right and has not been touched.** The unsubscribe token identifies one *subscription* — one address on one list — so the link has always been per list. Somebody who leaves the newsletter stays on the events. That is the correct model and it is unchanged.

What was missing was everything after it. `unsubscribed.blade.php` was six lines: "you have been removed from X", full stop. It never mentioned that the same brand runs four other lists, and it offered no way to change any of them. On adriangoldner.com, which runs five (`newsletter`, `chorleitung`, `saenger`, `events`, `offers`), a reader who only wanted the events had to wait for four more mails and click four more links to get there. The realistic thing for them to do instead is press the spam button, and that costs the sending domain far more than the four subscriptions were worth.

**The page.** `GET /!/marketing/preferences/{token}` — the same token the unsubscribe link carries, in the same route group, with the same brand derivation 1.5.0 built (`SetBrandFromRouteValue` on `Subscription.token`). It lists every list of that brand with its current state and lets each one be switched on or off. `POST` to the same URL applies a selection or, as a separate and deliberately separated action, ends everything.

**No login, and that is a decision rather than an omission.** Almost no subscriber has an account on the site that mails them, and a registration form standing between a person and their unsubscribe is a dark pattern with a password field on it. The token is the credential. There is also no session to read the brand from — this page is opened by a stranger's browser, exactly like the unsubscribe link, which is why the derivation has to come from the token and not from context.

**The unsubscribe page is now the entry rather than the end.** The unsubscribe still happens on arrival, unchanged; the page then shows what is still running and the controls to change it. That is the one moment the reader is certain to be looking.

**"Unsubscribe from everything" means every list of this brand.** Not the CRM-wide opt-out. Somebody who is done with this brand's mailings has said nothing about another brand's, and nothing at all about transactional mail, which does not rest on consent in the first place. Where an install has switched on `marketing.unsubscribe.global_opt_out`, that setting still applies, through the ordinary unsubscribe path as everywhere else, rather than through a second rule here.

**Switching a list back on does not ask for a second double opt-in.** The token was delivered to the address it belongs to, which is the same proof a confirmation mail collects: send something to the mailbox and see it come back. Asking twice would re-establish a fact the click has already established, and would mean the only way back is to wait for a mail that, by definition, is no longer being sent. The restored consent is written to the LeadHub timeline with `reason: preference_center` and `consent_proof: unsubscribe_token`, so the record says *how* it was given and not merely that it exists. `markSubscribed()` takes that metadata now; called without it, it behaves exactly as before.

### Added — the rule that decides whether this page is a feature or a hole

The token is in **every** mail this brand sends. It has been through mail clients, forwarding rules, corporate scanners and link checkers. So it may end consent, and it may restore consent *the person themselves* ended. It may never lift a **block**.

A hard bounce, a spam complaint, and an opt-out an editor recorded by hand are decisions made *about* an address, not by whoever is holding one mail from it. A click must not undo them. On the page the row still appears and is not switchable, with one sentence that says sending to this address is blocked and nothing more: the reason is known internally and is deliberately not rendered, because the page cannot know who is reading it.

**The check is bound to the state the addon already keeps, not to a suppression list of its own.** `do_not_contact` on the LeadHub contact is what `leadhub.hard_bounce_opt_out` sets, what `leadhub.complaint_opt_out` sets, what `marketing.unsubscribe.global_opt_out` sets, and what the CRM sets by hand. One question covers all four sources, and there is no second list to drift out of step with the first. Row-level blocks — a subscription sitting at `bounced` or `complained` — are read off the row.

Blocked rows are left alone in **both** directions. Saving the form without a blocked row's checkbox does not rewrite it to `unsubscribed`: that status is the reason the sending path stopped, and overwriting it as a side effect of somebody adjusting a different list would lose it.

**The enforcement is in the service, not the template.** A disabled checkbox is a suggestion; a crafted POST is not. `SubscriptionPreferences::apply()` refuses regardless of what arrives, and the test that matters most in this release posts the full list of handles for a blocked contact and requires that nothing is created and nothing is changed.

### Added — what the token discloses, written down on purpose

The page shows the address the token belongs to and which of this brand's lists it is on. That is a deliberate trade, recorded in `PreferenceCenter`'s own docblock rather than left implicit: the token was delivered to that mailbox and is repeated in every mail the brand sends, so whoever holds it can already read the mail those memberships are visible in. It grants nothing that was not already there, and without it the page cannot do its job.

What the token must not do is widen. It never reaches past its own brand, and a handle belonging to another brand is answered exactly as a handle nobody has — telling a stranger which handles exist elsewhere on the install is telling them which brands exist.

### Changed — the public pages can show what the server refused

1.5.3 took "the button did nothing and said nothing" out of the control panel. The same failure out here is worse, because a reader who cannot see why a change was refused does not file a ticket, they report the mail as spam. Errors that belong to a row are rendered under that row; anything else lands in a block above the form. Nothing the server says can fall through the floor.

### Unchanged — the RFC 8058 one-click path

`POST /!/marketing/unsubscribe/{token}` still answers `204` with an empty body and no page, and is still exempted from the forgery check. That endpoint is for Gmail and Outlook, not for people; giving it a body would break deliverability. A test now pins the empty body as well as the status, so the preference work cannot leak into it later.

### Added — twenty checks, and a demonstration of which rule each one holds

`tests/Feature/PreferenceCenterTest.php` (14) covers the page itself; `tests/Feature/PreferenceCenterBrandTest.php` (6) covers it under multi-brand. The suite is 183 on the flat driver and 182 on eloquent, from 163 and 162.

**Demonstrated rather than asserted.** With `src/`, `routes/` and `resources/` stashed and the two test files left in place, 18 of the 20 fail and the 163 that were green before stay green. The two that pass are meant to: one pins that the RFC 8058 endpoint is unchanged, the other pins the premise the multi-brand file rests on.

Two narrower demonstrations name what each group actually holds:

- With the block check neutralised so it always answers "not blocked", exactly two cases fail, and the first thing they report is a *new subscription created for a contact the sending is blocked for*. Nothing else in the file moves.
- With the brand derivation removed from the two new routes, five of the six multi-brand cases fail. The sixth passes, correctly: it asserts that both brands' subscriptions carry the **same** `contact_uuid`, because LeadHub keeps one contact per address. That is the premise — the identity that finds a person's other subscriptions matches across brands on its own, and only the brand scope stops it. It is used as the same address in both brands throughout that file for exactly this reason.

### Added — German and English text for the page

`resources/lang/{de,en}/public.php`. Somebody reading this page came to leave, so the tone is the same plain register the rest of the public pages use: no exclamation marks, no persuasion, no offer to reconsider. The one sentence about blocked rows says that sending is blocked and stops there.

## 1.6.5 — 2026-07-30

### Fixed — the scheduled send was registered twice

`schedule:list` carried `marketing:send-scheduled` twice on every real install. The registration hung off `app->booted()`, and in a Statamic application those callbacks fire twice — something this package already knew, because `registerSiblingBridges()` says so in its own comment and leans on the bridges being idempotent. A schedule registration is not idempotent, and nothing had noticed.

Measured rather than reasoned about: `registerSchedule()` is called once, the booted callback runs twice.

**Nothing broke, and only by accident.** `onOneServer()` with a fixed name means the second copy loses the mutex and is skipped. That is luck, not design — the next command added here without `onOneServer()` would simply run twice. `callAfterResolving(Schedule::class)` binds to the Schedule singleton instead, so the callback runs once no matter how often the application announces that it has booted.

### Added — a check that can actually go red

The first version of the accompanying test passed against the unfixed provider, because Testbench fires the booted callbacks only once and never reproduced the condition. It now replays them, which is what a Statamic application does. That replay is the load-bearing part of the file: a check that cannot fail is not coverage.

It counts whatever is registered rather than asserting against today's list, so a command added later is covered without anyone remembering to come back. It is scoped to this package's own commands — a sibling carrying the same defect is a finding to report there, not a reason to fail here.

## 1.6.4 — 2026-07-28

### Fixed — updating from before 1.3.0 dropped the consent unique and did not replace it

**Affected: installs created under 1.2.1 or earlier that updated to 1.6.1, 1.6.2 or 1.6.3. Nothing else.** An install that had already run `2026_07_24_100001` — anything on 1.3.0 or later — is untouched by this, because a migration that is recorded as run never runs again.

**How to tell whether it happened to you.** Update to 1.6.4 and run:

```
php artisan marketing:consent-integrity
```

It reads the indexes that are on `marketing_subscriptions` right now and the rows that are in it, and says plainly whether one address on one list is still one consent record. It changes nothing.

Three other fingerprints, in case the update is still in front of you rather than behind you:

- `php artisan migrate` stopped with `SQLSTATE[42000] … 1072 Key column 'uniqueness_key' doesn't exist in table` (MySQL) or `SQLSTATE[23000] … UNIQUE constraint failed: index 'ms_brand_list_email_unique'` (SQLite);
- running it a second time stopped with something else entirely — `1060 Duplicate column name 'brand_id'`, or `duplicate column name: brand_id` — which is the interrupted first step complaining, not the actual problem, and is the message most likely to send you looking in the wrong place;
- `select * from migrations where migration like '%add_brand_id_to_marketing%'` returns nothing, while `marketing_subscriptions` already has a `brand_id` column.

**What was wrong.** 1.6.1 added `uniqueness_key` to the create-table migration for `marketing_subscriptions` and, in the same commit, rewrote the already-published `2026_07_24_100001` to build the consent unique over that column. On a fresh install the column is there, because the create-table migration now makes it. On an install created before 1.3.0 the table predates the column, the create-table migration is recorded as run and never runs again, and `2026_07_28_000002` — the migration that adds the column to existing installs — sorts *after* the one that had already started using it.

**What that cost.** Not the abort. The state it left behind. Neither engine rolls DDL back, and the statement that failed came *after* the one that dropped `(list_handle, email_normalized)`. So the update ended with `marketing_subscriptions` carrying no consent unique of any kind, and with the migration not recorded, so nothing in the install knew. The sign-up form kept working; it stopped refusing duplicates. The most expensive promise this addon makes — one address, one list, one consent record — was open, silently, until somebody happened to look.

The two engines got there differently and it is worth writing down. MySQL refuses outright with ERROR 1072. SQLite reads a double-quoted identifier that resolves to no column as a *string literal*, so `create unique index … ("brand_id", "uniqueness_key")` quietly became a unique over `brand_id` and a constant — unique on the brand alone. On an empty table that is accepted and then corrected seconds later by `2026_07_28_000002` in the same `migrate` run, which is exactly why it was never seen here: the development hub and every test install are empty. On a table with rows the second row collides and the statement dies.

**What changed.** `2026_07_24_100001` now decides which consent unique to build from the schema it actually finds. Where `uniqueness_key` exists it builds the hash index as before. Where it does not, it builds the brand-scoped natural tuple `(brand_id, list_handle, email_normalized)` — which is not a new invention but precisely the index 1.3.0 through 1.6.0 shipped, fits InnoDB at 2048 of 3072 bytes, and is converted to the hash form by `2026_07_28_000002` moments later in the same run.

Correcting the order alone would have fixed the next install and left every install that already broke exactly as broken, so the whole migration is now re-runnable: it adds `brand_id` only where it is missing, drops only indexes that are actually present, does nothing at all where the wanted index is already in place, and stops with the offending values named rather than a bare integrity error where the rows cannot carry the index. Re-running it on a half-migrated install finishes the update and puts the consent unique back.

**If duplicates were created in the meantime.** They are real sign-ups that a form accepted while the constraint was missing, so nothing here deletes them. `php artisan migrate` refuses and names the list/address pairs it found; `marketing:consent-integrity` prints every colliding row with its id, status, confirmation date and source. Which of them is *the* consent record — the one whose confirmation timestamp the install will stand behind if anybody asks — is a question about people, not about rows. Delete the others by hand, then migrate again. `marketing:consent-integrity --repair` rebuilds the index alone once nothing is in the way, and refuses while anything is.

### Added — the migrations are finally tested against a database with data in it

This is the actual finding. Not the wrong order — the fact that no migration path in this addon was ever run over anything but empty tables, so a defect that only exists when rows are present had nowhere to be caught. Three releases went out green.

`tests/Migrations/` is a suite of its own, on a connection of its own, and it is in both `phpunit.xml` and `phpunit.mysql.xml`, because the failure behaves differently on each engine and one run cannot speak for the other.

It does not name the two migrations that were broken. It walks `database/migrations/` and runs the files one at a time, seeding every marketing table that exists before each one — so every migration in the addon meets rows written by an older schema, including migrations added long after this was written. `tests/Fixtures/released-migrations/` holds the migration sets as published in 1.2.1, 1.6.0 and 1.6.3, and the suite installs each of them, puts data in and upgrades forward: twelve sign-ups across five lists, two addresses on two lists each, one differing from another only by case, and every lifecycle state the double opt-in flow can leave behind.

The half-migrated install is not described from memory, it is produced: the suite runs the 1.6.3 migrations exactly as published, watches them die, confirms the consent unique is gone by writing a duplicate that the database accepts, and only then applies the current ones and requires the constraint back.

**Every check is behavioural.** "The migration ran" and "the constraint is there" are not the same statement, and mistaking one for the other is the whole defect. So nothing here asserts that `migrate` exited zero, or that an index of a given name exists. It writes the row the constraint is supposed to refuse and requires the database to refuse it.

Demonstrated rather than asserted: with the 1.6.3 file put back in place, four of the nine cases fail — on SQLite with the unique violation, on MySQL with ERROR 1072 and then the misleading `1060 Duplicate column name 'brand_id'` on the retry. The cases that keep passing are the fresh-install ones, which is exactly the coverage that existed before and exactly why none of this was found.

### Changed — the MySQL key-length probe can read the schema it is measuring

`tests/Unit/IndexKeyLengthTest.php` compiles the migrations through Laravel's MySQL grammar in pretend mode to measure index bytes without a server. Under `pretend()` a `select` returns nothing, so a migration that asks `Schema::hasColumn()` or `Schema::getIndexes()` before deciding what to build was being told the table is empty of everything — which, now that `2026_07_24_100001` branches on exactly those answers, would have had the probe measuring a schema no install ever holds.

It now runs two connections interleaved: the probe compiles the DDL through MySQL's grammar, and a real SQLite database one file behind answers every question the migrations ask about the current schema. Same measurements, on the schema that actually results.

## 1.6.3 — 2026-07-28

### Changed — the route parameter guard checks the rule, not a snapshot of the siblings

No defect in this addon, no route changed, and nothing in `src/` was touched. What changed is that 1.6.2's guard test was asserting something false.

That test carried a hand-written map of the names other installed packages bind application-wide, and it named `webhook`, `endpoint`, `rule` and `template` as claimed by `goldnead/statamic-webhook-manager` and `automation` as claimed by `goldnead/statamic-automations`. Webhook-manager renamed its four in its 1.7.0 and automations renamed its one in its 1.6.0. All five names are free. The entries were harmless — an entry for a name nobody binds matches nothing, which is why the suite stayed green — but a check that describes the world incorrectly is a check nobody can rely on, and correcting the five names would only have reset the clock on the same problem.

A snapshot of the siblings can only ever describe them as they are today. It says nothing about the addon that starts binding `{handle}` next month, which is exactly the case that hurts, and it has to be maintained by five repositories at once. What replaces it is the rule webhook-manager arrived at in its 1.7.0:

> **A `Route::bind()` is registered on the router, not on the package that calls it. Bind only names that unambiguously belong to your addon — specific enough that no sibling would reach for one by accident. Names you do *not* bind may stay as generic as they like: nothing resolves them, so nothing can be taken from anyone.**

That is a property of *this* package, so this package's own suite can enforce it without knowing anything about its neighbours.

`it binds only parameter names that belong to this addon` reads the `Route::bind()` calls out of this package's own `src/` — comments stripped, string literals only, and a call whose name is not a literal fails the test rather than escaping it — and requires every name found to match `marketing` + a capital. This addon binds nothing at all today, so the rule costs it nothing, which is precisely why it is worth pinning now: the binding that hurts is never the one somebody weighed, it is the one added later because binding by the entity's obvious name looked like the obvious thing to do.

`it does not swallow a sibling addon's generic route parameter` is the behavioural half. `tests/TestCase.php` now mounts stand-in routes for a sibling package — `{automation}`, `{rule}`, `{template}`, `{webhook}`, `{endpoint}`, `{handle}`, `{id}`, `{slug}`, `{record}`, each doing nothing but echoing its own value — and the test asserts every one answers with what it was given. They live in the bed rather than in the test body deliberately: a route added from inside a test body is shadowed by Statamic's `{segments?}` frontend catch-all and answers 404 whatever the bindings do, which would have made the check pass for the wrong reason.

Demonstrated rather than asserted: with a `Route::bind('handle', …)` added to a service provider in this family, the old three-test file stayed **green on all three**, while the new file fails three of its five and names `{handle}` in both directions — once as bound-but-not-ours, once as a sibling route answering 404 instead of its own value.

`1.6.2`'s first test is kept as it was: it pins that the CP bed mounts `SubstituteBindings`, without which no `Route::bind()` has any effect in tests and the whole file would pass for nothing. So is the check against `statamic/cms`, reduced to the ten CMS entity names it actually binds — that list is third-party, short and stable, and stays hand-kept for the same reason the sibling list could not.

**What deliberately did not change: `{handle}`, `{subscription}`, `{token}` and `{uuid}`. They are generic and they are staying. Renaming them would move text without removing any exposure, because they are not bound — nothing resolves them, so nothing can collide. The rule above is what protects them: a package that binds must bind a name of its own, and `handle` is nobody's own.**

## 1.6.2 — 2026-07-28

### Added — the suite can finally see route bindings

No defect in this addon. What is fixed is that the suite could not have found one, for a whole class of failure.

`Route::bind()` is registered on the router, not on a package. A binding one addon registers for `{rule}` or `{template}` applies to every route with that parameter name in every other addon installed beside it. `goldnead/statamic-leadhub` 1.8.0 shipped `/scoring/{rule}` while `goldnead/statamic-webhook-manager` binds `rule` to its own rule repository, and on the production hub, which has both, editing or deleting a scoring rule resolved against the wrong repository, returned 404, and reported nothing. A button that did nothing and said nothing, through a release.

**Why a green suite would not have found the same thing here.** `tests/TestCase.php` mounted the CP routes without `SubstituteBindings`. That middleware is part of Statamic's real CP middleware group and is the thing that actually applies a route binding — without it, every `Route::bind()` in the process was inert in this bed, including any a sibling addon registers. The failure was not under-tested, it was unobservable: no test written in this suite could have exhibited it. The middleware is now part of the CP route group here. Nothing in this addon uses implicit model binding — every routed controller method takes `string $handle` — so it changes no other behaviour, and the 147 tests that were green before are green after.

Demonstrated rather than asserted: with the middleware taken out again, the first case in the new `tests/Feature/RouteParameterCollisionTest.php` fails and everything else stays green.

### Added — the route parameter names are checked against the rest of the family

`tests/Feature/RouteParameterCollisionTest.php` reads this addon's own parameter names out of `routes/cp.php` and `routes/web.php` and checks them two ways.

The first is exact: a hand-maintained list of the names that packages installed beside this one bind application-wide, read off the running hub — `automation` from statamic-automations, `webhook` / `endpoint` / `rule` / `template` from statamic-webhook-manager, and the ten CMS entity names from statamic/cms. Using one of those is a live defect, and the test names the package that would swallow the route. Renaming `/{handle}/preview` to `/{template}/preview` makes it fail with exactly that sentence.

The second is a judgement call made explicit: `handle` and `token` are generic enough that a sibling could claim either tomorrow, so they are recorded in the test with their reason. A *new* generic parameter fails until somebody either renames it or writes down why it stays.

**What this cannot do.** A collision only exists once two packages are installed together, and no package can see its siblings from inside its own suite. The reserved list is a snapshot maintained by hand; it will not catch an addon that starts binding a name nobody binds today, and `handle` — used here for lists, campaigns and templates alike — is precisely such a name. The hub remains the only place the real answer is measurable. What the test does buy is that the next `{rule}` fails in the addon that introduces it, before it reaches a hub.

This addon's four parameters (`handle`, `subscription`, `token`, `uuid`) collide with nothing bound today.

## 1.6.1 — 2026-07-28

### Fixed — the consent unique was two thirds of the way to being unbuildable

`ms_brand_list_email_unique` spanned `(brand_id, list_handle, email_normalized)`.
Under utf8mb4 every character costs four bytes, so each `varchar(255)` costs
1020 and the index MySQL builds is **2048 of the 3072 InnoDB allows**. It
worked. It was also the addon's most important constraint — one address, one
list, one brand, one consent record — sitting one added column away from a
migration that fails with SQLSTATE 1071 — which is what kept two
`statamic-notifications` tables from ever being created on the production hub,
through four releases.

**Why a green suite did not find this.** The suite runs on in-memory SQLite,
and every mechanism in that paragraph is a MySQL mechanism. SQLite has no index
length limit, stores no fixed column widths (it accepts `varchar(255)` and
ignores the 255), and has no per-character byte cost to multiply. The migration
was not passing a test — there was no test for it to pass, because the
constraint it approaches does not exist in the engine the tests use. 136 green
tests and a schema whose limits were never once measured is not a contradiction;
it is the same blind spot in every addon in this family.

**Why the index was replaced rather than shortened.** A prefix index
(`list_handle(64)`) would have fit and would have been the smaller diff. It
would also have declared two lists whose handles share their first 64
characters to be one list — swapping a migration that fails loudly for consent
records that are quietly merged. Narrowing the columns themselves is worse
still: a handle is generated from a name nobody caps, and an address is not
ours to truncate.

`marketing_subscriptions` now carries a `uniqueness_key` — a SHA-256 of
`(list_handle, normalized email)`, maintained by the model on every save — and
the unique is `(brand_id, uniqueness_key)`, **264 bytes**. Every character of
both values is still covered, nothing is truncated, and `brand_id` stays a
column of the index rather than an ingredient of the hash, so the tenant
boundary remains legible in the schema and usable as a range. Two brands still
hold fully independent consent for the same address on the same list, which is
the guarantee the brand column exists for. `SubscriptionService::subscribe()`
looks a subscription up by the same key the index is built on, so the check and
the constraint can no longer disagree about what "already subscribed" means.

**Every other unique was measured too.** The widest remaining are the three
`(brand_id, handle)` uniques at 1028 bytes and the across-all-brands
`marketing_lists.handle` at 1020 — all under half the limit, which is now the
asserted rule rather than an accident. And none of them covers a nullable
column: a SQL unique does not constrain NULL, so an index over a nullable column
enforces nothing for exactly the rows it exists for. That is what let a whole
recipient type in notifications never have a uniqueness guarantee at all. It was
checked here and this addon does not have it — `uniqueness_key` is NOT NULL for
the same reason.

### Fixed — a rejected handle on an edit form was shown nowhere

1.5.3 made every mask render what the server sent back, and split the work in
two: keys with a field of their own are shown at that field, everything else in
a summary above the form. `handle` was on the first list — correct while
creating, where the handle input exists, and wrong on an update, where it is
`v-if="isCreating"` and therefore absent. A rejected handle was filtered out of
the summary as "already shown at its field" and had no field to be shown at. It
was rendered nowhere, which is exactly the failure 1.5.3 set out to end.

The campaign and template controllers validate `handle` through the same shared
validator on store and on update, so this was one changed payload away from
being reachable. The three edit pages now decide per key whether its field is
actually on screen, and anything without one falls through to the summary.

### Fixed — the last `Field` that sized itself with `flex-1`

The subscriber filter row still had `<Field class="flex-1 sm:max-w-xs">` for the
search box. That is the same trap 1.5.1 fixed one row above it: `flex-1` is
`flex: 1 1 0%`, Statamic's `Field` brings its own `min-w-0`, and together they
remove the floor that stops a column collapsing. `sm:max-w-xs` cannot help — a
max-width is not a floor, which is why the 1.5.0 attempt failed. It has the
explicit width the add-subscriber row uses.

### Added — a JavaScript test layer

`npm test` runs Vitest against the Control Panel components — **24 tests** over
the pages this release touched. Adopted from `statamic-webhook-manager` 1.6.0,
which established the shape: no second build chain, the existing `vite.config.js`
swaps the Statamic Vite plugin for the plain Vue plugin under `VITEST`, and
`tests/js/setup.js` installs the `__STATAMIC__` global the `@statamic/cms/*`
shims destructure at import time.

It is not backfilled coverage. It covers the classes of defect this round found:
that a rejected form says so at the field or in the summary and not nowhere,
that a `Field` never sizes itself with `flex-1`, and that the handful of places
where a stored `false` or a `0` has to survive the round trip still do —
`recipients: 0` reads as none rather than as unknown, and a list whose double
opt-in is explicitly off is not read as "use the default". Each of those is a
one-character edit away from being wrong and every existing test would have
stayed green.

Both fixes above were written against a failing test. Every guard was verified
by breaking the thing it guards and watching it go red.

### Added — the schema can be measured against MySQL, without MySQL

`tests/Unit/IndexKeyLengthTest.php` compiles the addon's own migration files
through Laravel's MySQL grammar in pretend mode — no server, no connection,
nothing to install in CI — and measures every index the way InnoDB would: total
key bytes, headroom against half the limit, and whether a unique covers a column
that may be NULL. It reads the real migration files, so it cannot drift from
them. Against the 1.6.0 schema it reports 2048 bytes and fails, which is the
check that was missing.

The whole suite can also be run against a real MySQL server:
`vendor/bin/pest -c phpunit.mysql.xml`. Same tests, `DB_DRIVER=mysql`. Both are
lifted from `statamic-notifications` 1.0.4.

### Migration

- `php artisan migrate`. The create-migrations are corrected too, so a new
  install never builds the wide index in the first place; that reaches nobody
  who already ran them, which is what the new migration is for.
- `2026_07_28_000002_rebuild_subscription_uniqueness_keys` adds the column,
  backfills it from the existing rows and swaps the index. It is idempotent and
  a no-op on a fresh install.
- No rows can be lost or merged. The new key is a pure function of the two
  columns the old unique already covered, and neither of them is nullable, so
  two rows cannot collide under the new index without having collided under the
  old one.

### Notes

- Suite green on both drivers: flat **147 passed + 7 skipped**, eloquent
  **146 passed + 8 skipped** (baseline 136 / 135). Plus **24** Vitest tests.
- Verified against **MySQL 8.4** as well as SQLite, which is the point of the
  exercise: `vendor/bin/pest -c phpunit.mysql.xml` — the same 147 passed and 7
  skipped.
- `tests/TestCase.php` now names its connection `testing` and honours
  `DB_DRIVER`. `EloquentUserCompatTest` pointed Statamic's user tables at the
  connection named `sqlite`, which was a second, empty in-memory database as
  soon as the suite's own connection was renamed; it follows the suite now.
- The first MySQL run turned up two test fixtures that only ever worked because
  SQLite is lenient, which is the whole argument for having the run: one wrote a
  41-character value into a `char(36)` column, and one attached a message to a
  subscription id that did not exist, across a foreign key SQLite does not
  enforce. Both are test-side; no production code was involved.

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
