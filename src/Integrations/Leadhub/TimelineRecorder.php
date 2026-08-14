<?php

namespace Goldnead\Marketing\Integrations\Leadhub;

use Goldnead\BrandContext\Sending\SaidRecently;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Support\SourceEvent;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\MessageBounced;
use Goldnead\Marketing\Events\MessageClicked;
use Goldnead\Marketing\Events\MessageComplained;
use Goldnead\Marketing\Events\MessageEventBase;
use Goldnead\Marketing\Events\MessageOpened;
use Goldnead\Marketing\Events\MessageOpenedByHuman;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Support\MachineOpen;
use Illuminate\Support\Facades\Log;

/**
 * Every mail this addon sends, written onto the recipient's LeadHub timeline.
 *
 * The two addons were married underneath and mute to each other on screen: a
 * contact record showed tags, tasks and notes, and said nothing about the six
 * mails the person had received from us or the two they had clicked. The facts
 * were all recorded — in `marketing_messages` and `marketing_message_events`,
 * keyed by message, where nobody looking at a person would ever find them.
 *
 * **Only for contacts that already exist.** A tracking pixel must not be able to
 * conjure a CRM record: somebody who signed up and never confirmed has no
 * contact on purpose, and an opened confirmation mail is not consent to be
 * filed. Where there is no contact, nothing is written and nothing is logged as
 * a failure — that is the correct outcome, not a missed one.
 *
 * **Opens are marked, not trusted.** Apple's Mail Privacy Protection fetches the
 * pixel for every message it delivers whether or not anybody looks, so an open
 * says much less than it appears to; {@see MachineOpen}
 * decides as far as anything can, and a machine open is filed as exactly that.
 * A click is the entry on this timeline that means a person did something.
 */
class TimelineRecorder
{
    /** Timeline event types, prefixed so they cannot collide with LeadHub's own. */
    public const TYPE_SENT = 'marketing.mail_sent';

    public const TYPE_OPENED = 'marketing.mail_opened';

    public const TYPE_PREFETCHED = 'marketing.mail_prefetched';

    public const TYPE_CLICKED = 'marketing.mail_clicked';

    public const TYPE_BOUNCED = 'marketing.mail_bounced';

    public const TYPE_COMPLAINED = 'marketing.mail_complained';

    public static function available(): bool
    {
        if (! config('marketing.timeline.enabled', true)
            || ! class_exists(LeadHub::class)
            || ! class_exists(SourceEvent::class)) {
            return false;
        }

        // A LeadHub old enough to lack these is not a failure to report, it is
        // an installation without the feature — the same courtesy every other
        // optional seam in this family shows. Without the check the first send
        // against such an install would warn once per recipient about a method
        // that was simply never there.
        try {
            $root = LeadHub::getFacadeRoot();
        } catch (\Throwable) {
            return false;
        }

        return is_object($root)
            && method_exists($root, 'findByEmail')
            && method_exists($root, 'ingest');
    }

    /**
     * Registered once, however often the provider announces that it has booted.
     *
     * The sibling wiring runs from a doubled `booted()` callback (see the
     * ServiceProvider), so without this every event would be handled twice.
     * `ingest()` is idempotent by dedupe key so nothing would be written twice
     * either way — but it would be two lookups per mail for no reason.
     */
    protected bool $subscribed = false;

    public function subscribe(mixed $events): void
    {
        if ($this->subscribed || ! static::available()) {
            return;
        }

        $this->subscribed = true;

        $events->listen(MessageSent::class, [self::class, 'sent']);
        $events->listen(MessageOpened::class, [self::class, 'opened']);
        // The reading, after a scanner already fetched the image. Without this
        // the record would say "preloaded" forever for every mailbox behind a
        // filter and never once say somebody read it.
        $events->listen(MessageOpenedByHuman::class, [self::class, 'openedByHuman']);
        $events->listen(MessageClicked::class, [self::class, 'clicked']);
        $events->listen(MessageBounced::class, [self::class, 'bounced']);
        $events->listen(MessageComplained::class, [self::class, 'complained']);
    }

    public function sent(MessageSent $event): void
    {
        $this->record($event, self::TYPE_SENT, 'sent');
    }

    public function opened(MessageOpened $event): void
    {
        $machine = (bool) ($event->metadata['machine'] ?? false);

        $this->record(
            $event,
            $machine ? self::TYPE_PREFETCHED : self::TYPE_OPENED,
            $machine ? 'prefetched' : 'opened',
        );
    }

    public function openedByHuman(MessageOpenedByHuman $event): void
    {
        $this->record($event, self::TYPE_OPENED, 'opened');
    }

    public function clicked(MessageClicked $event): void
    {
        $this->record($event, self::TYPE_CLICKED, 'clicked');
    }

    public function bounced(MessageBounced $event): void
    {
        $this->record($event, self::TYPE_BOUNCED, 'bounced');
    }

    public function complained(MessageComplained $event): void
    {
        $this->record($event, self::TYPE_COMPLAINED, 'complained');
    }

    /**
     * The line a reader sees, with the subject when there is one.
     *
     * A message whose campaign has since been deleted has no subject to name,
     * and "Mail versendet:" with a dangling colon after it is the kind of small
     * wrongness that makes a screen look unfinished. Two strings per kind, and
     * the one without a subject reads as a whole sentence.
     */
    protected function summary(string $kind, Message $message): string
    {
        $subject = $this->subject($message);

        return (string) ($subject === ''
            ? __('marketing::timeline.'.$kind.'_untitled')
            : __('marketing::timeline.'.$kind, ['subject' => $subject]));
    }

    /**
     * Write one entry, or decide not to.
     *
     * Never throws, and that includes building the line it writes. This hangs
     * off the send path and off two public tracking endpoints: a CRM that is
     * mid-upgrade, a corrupt campaign file, a timeline table that is momentarily
     * unavailable — none of them may turn into a failed send or a broken
     * tracking pixel. A warning in the log is the whole of the consequence.
     */
    protected function record(MessageEventBase $event, string $type, string $kind): void
    {
        if (! static::available() || ! in_array($type, $this->enabledTypes(), true)) {
            return;
        }

        $message = $event->message;
        $email = (string) $message->email;

        if ($email === '') {
            return;
        }

        try {
            // The summary is built in here, not passed in. It resolves the
            // campaign through the repository, and a corrupt campaign file is
            // exactly the kind of thing that must not escape: this runs on the
            // send path and on two public tracking endpoints. Built as an
            // argument it would have been evaluated before the try began — and
            // before the two guards above had a chance to skip the work.
            $summary = $this->summary($kind, $message);

            // The contact has to exist already. A pixel must not create one.
            if (LeadHub::findByEmail($email) === null) {
                return;
            }

            LeadHub::ingest(new SourceEvent(
                email: $email,
                type: $type,
                summary: $summary,
                sourceType: 'marketing_message',
                sourceId: $message->uuid,
                // One entry per fact per message. Every one of these events fires
                // once — an open and a click are gated on being the first — so
                // the message and the kind are the whole identity, and a retried
                // job cannot turn one reading into two.
                dedupeKey: $type.':'.$message->uuid,
                payload: $this->payload($message, $type),
            ));
        } catch (\Throwable $e) {
            // Throttled per kind of failure, not written per recipient. A send
            // to five thousand people with a CRM that is down would otherwise
            // put five thousand identical lines in the log and bury the one
            // that matters. The same helper the sender uses for the same
            // reason.
            if (SaidRecently::shouldSay('timeline:'.$type.':'.$e::class)) {
                Log::warning('marketing: could not write the mail event to the LeadHub timeline.', [
                    'type' => $type,
                    'message' => $message->uuid,
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * What the timeline shows under the summary line.
     *
     * `detail` is a list of label/value pairs that LeadHub renders as readable
     * lines. Everything else in here is for whoever reads the raw payload later;
     * no tracking identifier that is not already the recipient's own is added.
     *
     * @return array<string, mixed>
     */
    protected function payload(Message $message, string $type): array
    {
        $campaign = $this->campaign($message);

        $detail = array_values(array_filter([
            $this->subject($message) !== '' ? [
                'label' => (string) __('marketing::timeline.detail_subject'),
                'value' => $this->subject($message),
            ] : null,
            $campaign !== null ? [
                'label' => (string) __('marketing::timeline.detail_campaign'),
                'value' => $campaign->name,
            ] : null,
            $message->subscription?->list_handle ? [
                'label' => (string) __('marketing::timeline.detail_list'),
                'value' => (string) $message->subscription->list_handle,
            ] : null,
            $type === self::TYPE_PREFETCHED ? [
                'label' => (string) __('marketing::timeline.detail_note'),
                'value' => (string) __('marketing::timeline.prefetched_note'),
            ] : null,
        ]));

        return [
            'detail' => $detail,
            'message_uuid' => $message->uuid,
            'campaign' => $message->campaign_handle,
            'list' => $message->subscription?->list_handle,
            'opens' => (int) $message->opens,
            'clicks' => (int) $message->clicks,
        ];
    }

    /**
     * The subject line this recipient saw.
     *
     * A message does not carry one — it is the campaign's, and for an A/B test
     * the variant's. Resolved from the campaign and remembered on this
     * singleton, because a send fires this once per recipient and a repository
     * lookup per mail would be a query per mail.
     *
     * The memo therefore outlives a single job in a queue worker. A subject
     * edited mid-send would reach later recipients' timeline entries in its old
     * wording — the mails themselves are unaffected, and a campaign being
     * rewritten while it sends is not a state this addon supports anyway.
     */
    protected function subject(Message $message): string
    {
        $campaign = $this->campaign($message);

        if ($campaign === null) {
            return (string) ($message->template_handle ?? '');
        }

        return $message->variant === 'b' && filled($campaign->variantSubject)
            ? (string) $campaign->variantSubject
            : (string) $campaign->subject;
    }

    /** @var array<string, Campaign|null> */
    protected array $campaigns = [];

    protected function campaign(Message $message): ?Campaign
    {
        $handle = (string) ($message->campaign_handle ?? '');

        if ($handle === '') {
            return null;
        }

        // `array_key_exists`, not `??=`: a handle that resolves to nothing is an
        // answer worth remembering. With `??=` a message pointing at a deleted
        // campaign would be looked up again for every recipient, and twice per
        // recipient at that — `payload()` asks for the subject as well.
        if (! array_key_exists($handle, $this->campaigns)) {
            $this->campaigns[$handle] = app(CampaignRepository::class)->find($handle);
        }

        return $this->campaigns[$handle];
    }

    /**
     * Which of the six kinds are written.
     *
     * All of them by default. An installation that sends to fifty thousand
     * people and does not want a row per open on every contact narrows the list
     * in config rather than losing the feature.
     *
     * @return array<int, string>
     */
    protected function enabledTypes(): array
    {
        $configured = config('marketing.timeline.types');

        return is_array($configured) && $configured !== [] ? $configured : [
            self::TYPE_SENT,
            self::TYPE_OPENED,
            self::TYPE_PREFETCHED,
            self::TYPE_CLICKED,
            self::TYPE_BOUNCED,
            self::TYPE_COMPLAINED,
        ];
    }
}
