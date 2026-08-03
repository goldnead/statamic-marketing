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
use Illuminate\Support\Facades\Mail;
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
        $class ??= $campaign->mailClass();
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
            'campaign_handle' => $campaign->handle,
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => Message::STATUS_PENDING,
            'brand_id' => $brandId,
        ]);

        try {
            $rendered = $this->renderer->render($campaign, $list, $subscription, $message);

            Mail::mailer(config('marketing.sending.mailer'))
                ->to($email)
                ->send(new CampaignMail($campaign, $rendered));

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
        $this->cap->record($email, $class, $brandId, $reference ?? $campaign->handle);

        event(new MessageSent($message->fresh()));

        return SingleSendResult::sent($message->fresh() ?? $message);
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
