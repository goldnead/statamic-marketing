<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The marketing send path for exactly one recipient.
 *
 * `StartCampaignJob` + `SendMessageJob` are the same path for a whole list:
 * snapshot an audience, fan out, deliver. A sequence has no audience — it has
 * one person, arriving at one step of a flow, at a moment nobody planned. This
 * class is that path with the fan-out removed, and it exists so the removal
 * does not also remove the four things the fan-out path does on the way:
 *
 *   1. **Consent.** The mailing list is where consent lives. No subscribed
 *      subscription on the configured list, no mail — not even to an address
 *      the flow otherwise knows perfectly well.
 *   2. **Suppression.** The hard no, and the only gate that fails *closed*:
 *      a check that cannot be answered blocks the send. Not knowing is not
 *      permission.
 *   3. **Preference.** LeadHub's `do_not_contact`, which is what the
 *      preference centre and an editor's manual opt-out both write. Also
 *      fail-closed, for the same reason `StartCampaignJob::contactOptedOut()`
 *      is.
 *   4. **Frequency cap.** The only gate that says "later" rather than "no",
 *      and therefore the last one asked: there is no point deferring a mail to
 *      an address that may never receive it at all.
 *
 * That order is the contract, not an implementation detail — see
 * `Contracts\FrequencyCap`'s class note, which states it from the other side.
 *
 * **Why a real `Message` row.** A sequence mail is a mail. It gets opens,
 * clicks, bounces, an unsubscribe link and an entry in the recipient's own
 * history, and every one of those hangs off `marketing_messages` in this
 * addon. Sending without a row would produce a second class of mail that the
 * reports cannot see and the ESP feedback loop cannot attribute.
 *
 * **Two entry points, one path.** {@see send()} sends a campaign;
 * {@see sendTemplate()} sends a managed email template to somebody who is on a
 * list. They differ in what the mail is made of and in what the message row
 * says it was — a campaign send fills `campaign_handle`, a template send fills
 * `template_handle` and leaves `campaign_handle` NULL, because there is no
 * campaign and a row naming one that does not exist would be counted by every
 * campaign report that went looking for it. Everything between those two
 * facts, including all four gates and their order, is the same code.
 *
 * **Why not `SendMessageJob`.** That job owns campaign *finalisation* — the
 * "last pending message marks the campaign sent" rule. A sequence campaign is
 * never queued and must never be marked sent, so reusing the job would flip a
 * campaign's lifecycle on the first delivery. The delivery itself is the same
 * three lines; the bookkeeping around it is not.
 */
class SingleSend
{
    public function __construct(
        protected CampaignRenderer $renderer,
        protected SuppressionGate $gate,
        protected FrequencyCap $cap,
        protected ContactRepository $contacts,
        protected BrandMailer $mailer,
    ) {}

    /**
     * Send one campaign to one subscriber, through every gate the list path
     * goes through.
     *
     * @param  MailClass|null  $class  the classification the cap acts on. Null
     *                                 takes the campaign's own, which defaults
     *                                 to `marketing` — so a mail nobody
     *                                 classified is capped rather than exempt.
     * @param  string|null  $reference  what to write in the mail log, e.g. the
     *                                  automation that sent it. Defaults to the
     *                                  campaign handle.
     */
    public function send(
        Campaign $campaign,
        MailingList $list,
        Subscription $subscription,
        ?MailClass $class = null,
        ?string $reference = null,
    ): SingleSendResult {
        return $this->deliver(
            $campaign,
            $list,
            $subscription,
            $class ?? $campaign->mailClass(),
            $reference ?? $campaign->handle,
            campaignHandle: $campaign->handle,
            templateHandle: null,
        );
    }

    /**
     * Send one managed email template to one subscriber, through the same four
     * gates in the same order.
     *
     * The mail a sequence sends is not always a campaign. A welcome mail is
     * often a `et_templates` entry addressed to one person — that is how the
     * domain-neutral `send_email` node in `automations` sends, and every mail
     * built that way was going out without consent, suppression, opt-out or the
     * cap ever being asked. This is the same path {@see send()} takes, with the
     * campaign replaced by the three things a template send actually has: a
     * layout, a subject and a classification.
     *
     * @param  string  $template  the `et_templates` slug (or a marketing
     *                            template handle). The template IS the mail
     *                            here, so a slug that resolves to nothing is a
     *                            failure rather than a fallback layout.
     * @param  MailClass|null  $class  null means `marketing` — the capped one,
     *                                 because a mail nobody classified must not
     *                                 be exempt by omission.
     * @param  string|null  $reference  what to write in the mail log. Defaults
     *                                  to the template slug, the way the
     *                                  campaign path defaults to its handle.
     */
    public function sendTemplate(
        string $template,
        string $subject,
        MailingList $list,
        Subscription $subscription,
        ?MailClass $class = null,
        ?string $reference = null,
    ): SingleSendResult {
        // Before the gates, because it is not about the recipient. A template
        // that answers to nothing is a broken node, and the answer to a broken
        // node is the same for everybody it would have been sent to.
        if (! $this->canResolveTemplate($template)) {
            return SingleSendResult::failed('template_unresolved', static::unresolvedTemplateMessage($template));
        }

        $class ??= MailClass::Marketing;

        return $this->deliver(
            $this->templateCampaign($template, $subject, $list, $class),
            $list,
            $subscription,
            $class,
            $reference ?? $template,
            campaignHandle: null,
            templateHandle: $template,
        );
    }

    /**
     * The four gates, the message row and the delivery — everything both entry
     * points do identically, which is everything except what the mail is made
     * of and what the row says it was.
     *
     * @param  string|null  $campaignHandle  what lands in `campaign_handle`.
     *                                       NULL for a template send: there is
     *                                       no campaign, and a row that names
     *                                       one that does not exist would be
     *                                       counted by every campaign report
     *                                       that goes looking for it.
     * @param  string|null  $templateHandle  what lands in `template_handle`.
     */
    protected function deliver(
        Campaign $campaign,
        MailingList $list,
        Subscription $subscription,
        MailClass $class,
        string $reference,
        ?string $campaignHandle,
        ?string $templateHandle,
    ): SingleSendResult {
        $email = (string) $subscription->email;

        // 1 — consent.
        if (! $subscription->isSubscribed()) {
            return SingleSendResult::blocked('not_subscribed');
        }

        // 2 — suppression. Fail closed.
        try {
            if ($this->gate->isSuppressed($email)) {
                return SingleSendResult::blocked('suppressed');
            }
        } catch (SuppressionCheckFailed $e) {
            report($e);

            return SingleSendResult::blocked('suppression_unavailable');
        }

        // 3 — preference / opt-out. Fail closed, same as the list path.
        if ($this->contactOptedOut($subscription)) {
            return SingleSendResult::blocked('opted_out');
        }

        $brandId = $subscription->brand_id === null ? null : (int) $subscription->brand_id;

        // 4 — frequency cap. Asked here, about the window that ends now.
        if (! $this->cap->allows($email, $class, $brandId)) {
            $retry = max(1, (int) config('marketing.frequency_cap.defer.retry_after_minutes', 1440));

            return SingleSendResult::deferred('frequency_cap', $retry);
        }

        $message = Message::query()->create([
            'campaign_handle' => $campaignHandle,
            'template_handle' => $templateHandle,
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => Message::STATUS_PENDING,
            'brand_id' => $brandId,
        ]);

        try {
            $rendered = $this->renderer->render($campaign, $list, $subscription, $message);

            // Same brand the message row was stamped with, same identity the
            // list path uses. A sequence mail is a mail.
            $this->mailer->send($brandId, $email, new CampaignMail($campaign, $rendered));

            // `now()` and never a zoned Carbon handed in from elsewhere:
            // Laravel's `datetime` cast serialises a zoned value without
            // converting it, so a timestamp from another zone lands in the
            // column off by that offset.
            $message->update(['status' => Message::STATUS_SENT, 'sent_at' => now()]);
        } catch (Throwable $e) {
            $message->update(['status' => Message::STATUS_FAILED, 'error' => $e->getMessage()]);

            report($e);

            return SingleSendResult::failed('send_failed', $e->getMessage());
        }

        // After delivery, never before it. A mail that threw did not reach
        // anybody and may not consume their budget.
        $this->cap->record($email, $class, $brandId, $reference);

        event(new MessageSent($message->fresh()));

        return SingleSendResult::sent($message->fresh() ?? $message);
    }

    /**
     * Does this template reference answer to anything at all?
     *
     * Public because the node that configures a template send has to be able to
     * report a broken reference as a *configuration* error, before it looks at
     * a recipient — a test run starts from an empty context and has nobody to
     * send to, and a broken template is exactly what a test run exists to find.
     */
    public function canResolveTemplate(string $template): bool
    {
        return $this->renderer->findTemplateHtml($template) !== null;
    }

    /**
     * A template send, described as the one thing the renderer knows how to
     * render.
     *
     * Not a campaign that gets saved, looked up, or written anywhere: it never
     * leaves this method's call stack, and `campaign_handle` on the message row
     * is NULL precisely so that nothing downstream can mistake it for one. It
     * exists because {@see CampaignRenderer} takes a campaign — and re-deriving
     * the tracking rewrite, the open pixel, the unsubscribe URLs, the
     * `List-Unsubscribe` header and the plain-text alternative for a second
     * kind of mail is how the two would drift apart on the first change to
     * either.
     *
     * `content` is empty on purpose. A managed email template is the whole
     * mail, not a frame with a hole in it: the renderer wraps the (empty)
     * content in the template body, which leaves the template body. A template
     * that does print `{{ content }}` renders nothing there, which is the
     * honest result — a template send has no content to put in it.
     */
    protected function templateCampaign(
        string $template,
        string $subject,
        MailingList $list,
        MailClass $class,
    ): Campaign {
        return new Campaign(
            // Empty rather than a made-up handle. `{{ campaign.handle }}` in a
            // template must not print something that cannot be looked up.
            handle: '',
            name: '',
            subject: $subject,
            listHandle: $list->handle,
            templateHandle: $template,
            content: '',
            mailClass: $class->value,
        );
    }

    /**
     * Why a template slug resolved to nothing, in the terms the reader can act
     * on.
     *
     * The two causes need different sentences. With the email-templates addon
     * installed, a slug that answers to nothing is a slug that is wrong or an
     * entry that was deleted. Without it, the entries the slug refers to cannot
     * be read at all — and saying "no such template" there would send somebody
     * looking for a typo in a name that is perfectly correct.
     */
    public static function unresolvedTemplateMessage(string $template): string
    {
        return CampaignRenderer::emailTemplatesInstalled()
            ? static::missingTemplateMessage($template)
            : static::emailTemplatesMissingMessage($template);
    }

    /** The addon is installed and still nothing answers to this slug. */
    public static function missingTemplateMessage(string $template): string
    {
        return "Email template [{$template}] does not exist, so nothing was sent. A template send has "
            .'no content of its own — a missing template is an empty mail, not a plain one.';
    }

    /**
     * The slug cannot be looked up at all, because the package that owns
     * managed templates is not installed.
     *
     * A separate sentence rather than a suffix on the one above, because the
     * two send a reader to different places. "No such template" would have
     * somebody hunting for a typo in a slug that is spelled perfectly
     * correctly.
     */
    public static function emailTemplatesMissingMessage(string $template): string
    {
        return "Email template [{$template}] could not be resolved: managed email templates come from "
            .'goldnead/statamic-email-templates, which is not installed, and no marketing template of '
            .'that handle exists either. Install the package, or point this node at a campaign instead.';
    }

    /**
     * Is this recipient barred from receiving marketing at all?
     *
     * A copy of `StartCampaignJob::contactOptedOut()`'s rule rather than a call
     * into it, because that method is a protected detail of a queued job. The
     * rule itself is the point and is repeated here in full: resolve by
     * contact uuid, fall back to the normalized address, and treat "no contact
     * resolvable" as opted out. Every path that confirms a subscription
     * creates or resolves a contact, so no contact means the CRM sync failed —
     * which may not be read as consent.
     */
    protected function contactOptedOut(Subscription $subscription): bool
    {
        $contact = $subscription->contact_uuid
            ? $this->contacts->findByUuid((string) $subscription->contact_uuid)
            : null;

        if (! $contact) {
            $normalized = (string) ($subscription->email_normalized
                ?: EmailNormalizer::normalize((string) $subscription->email));

            $contact = $normalized !== ''
                ? $this->contacts->findByEmailNormalized($normalized)
                : null;
        }

        if (! $contact) {
            Log::warning(
                'Marketing did not send a single-recipient mail to subscription ['
                .$subscription->uuid.'] on list ['.$subscription->list_handle.']: no LeadHub '
                .'contact resolvable by uuid or email, so the opt-out state cannot be '
                .'verified (fail-closed).'
            );

            return true;
        }

        return (bool) ($contact->do_not_contact ?? false);
    }
}
