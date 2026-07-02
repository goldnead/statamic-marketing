# Recipes: wiring the four addons together

How LeadHub (CRM), Webhook Manager (transport), Automations (orchestration),
and Marketing (email) combine into complete workflows. Every recipe lists the
addons it needs; Marketing always requires LeadHub.

## 1. Newsletter signup with double opt-in + welcome drip

*Marketing (+ Automations)*

1. Create a list `newsletter` (Marketing → Lists), leave double opt-in on.
2. Drop the signup form on your site:
   ```antlers
   {{ marketing:subscribe list="newsletter" }}
       <input type="email" name="email" required>
       <button>Subscribe</button>
   {{ /marketing:subscribe }}
   ```
3. With Automations installed, install the **Newsletter Welcome Series**
   template (Automations → Templates): `marketing.subscribed` → welcome mail →
   3-day delay → follow-up mail. Adjust copy and the list filter, enable.

The subscriber exists as a LeadHub contact the moment they confirm, tagged
`list:newsletter`, with a `marketing.subscribed` timeline entry.

## 2. Statamic form → newsletter list

*Marketing (+ Automations, alternative: plain listener)*

Install the **Form Submission to Newsletter** template: `form_submitted`
(pick your form) → `marketing.subscribe` action with
`{{ submission.data.email }}`. Double opt-in still applies, so this is safe
for any form — nobody gets mailed without confirming.

Without Automations you can call the same service from a listener:
`app(SubscriptionService::class)->subscribe($list, $email, [...])`.

## 3. Qualified lead → newsletter

*LeadHub + Marketing + Automations*

Install the **Qualified Lead to Newsletter** template:
`leadhub.lead_status_changed` (to `qualified`) → `marketing.subscribe` with
`{{ lead.email }}`. Sales qualifies a lead in the LeadHub pipeline, marketing
nurtures them automatically — with consent handled by double opt-in.

## 4. ESP bounce & complaint handling

*Marketing + Webhook Manager*

1. Webhook Manager → Inbound: create an endpoint (e.g. handle `mailgun-events`).
2. Auth: HMAC or a static header, matching what your ESP signs with.
3. Action: **Marketing: process ESP event**.
4. Mapping: either map the ESP payload to the normalized keys
   (`type` = bounce/complaint/unsubscribe, `email`, `hard`), or pass the raw
   body through with `provider` set to `mailgun` or `postmark` — the addon
   normalizes those shapes itself.
5. Point the ESP's webhook at the endpoint URL.

Hard bounces and complaints mark the subscription `bounced`/`complained` and
flag the LeadHub contact `do_not_contact`, so no list ever mails them again.

## 5. Marketing events → external systems

*Marketing + Webhook Manager*

Every marketing lifecycle event is available as an outbound webhook trigger:
`marketing.subscriber.subscribed`, `.pending`, `.unsubscribed`,
`marketing.campaign.sent`, `marketing.message.bounced`, `.complained`.

Example: Webhook Manager → Outbound → trigger `marketing.subscriber.subscribed`
→ Slack preset → "New subscriber: {{ payload:email }} on {{ payload:list }}".
Retries, signatures, and delivery logs come from Webhook Manager for free.

## 6. Campaign follow-ups

*Marketing + Automations*

- **Campaign Sent Notification** template: `marketing.campaign_sent` → email
  the team a pointer to the report.
- **Unsubscribe Alert** template: `marketing.unsubscribed` → log + notify,
  useful while tuning content or send frequency.

## 7. External signups → CRM → newsletter

*Webhook Manager + LeadHub + Marketing*

Inbound endpoint with the `upsert_lead` action (LeadHub) turns partner-site
signups into contacts; an automation on `leadhub.lead_created` (source filter)
chains `marketing.subscribe`. The double-opt-in mail closes the consent loop
for addresses that arrived from outside your site.

---

### Cheat sheet: what registers where

| Surface | Registered by | When |
| --- | --- | --- |
| Automation triggers `marketing.*` + actions | statamic-automations (its Marketing integration, like LeadHub) | automations detects marketing |
| Automation templates `marketing_*` | statamic-marketing's `AutomationsBridge` | marketing detects automations (needs `Automations::template()`) |
| Outbound webhook triggers `marketing.*` | statamic-marketing's `WebhookManagerBridge` | marketing detects webhook-manager |
| Inbound action `marketing.process_esp_event` | statamic-marketing's `WebhookManagerBridge` | marketing detects webhook-manager |
| LeadHub tags `list:*`, timeline events, opt-outs | statamic-marketing core | always (hard dependency) |
