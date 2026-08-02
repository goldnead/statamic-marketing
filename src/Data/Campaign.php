<?php

namespace Goldnead\Marketing\Data;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\MailClass;

/**
 * A campaign (broadcast) definition. Content is Antlers-enabled HTML that is
 * rendered per recipient and wrapped in the referenced template layout.
 *
 * Lifecycle: draft -> scheduled -> sending -> sent.
 */
class Campaign
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public function __construct(
        public string $handle,
        public string $name,
        public string $subject = '',
        /**
         * The subject line variant B receives. `null` (or empty) means this
         * campaign is not an A/B test and everybody gets `$subject` — the
         * whole feature is opt-in through this one field.
         */
        public ?string $variantSubject = null,
        public ?string $preheader = null,
        public ?string $fromName = null,
        public ?string $fromEmail = null,
        public ?string $replyTo = null,
        public ?string $listHandle = null,
        public ?string $segmentHandle = null,
        public ?string $templateHandle = null,
        public string $content = '',
        public string $status = self::STATUS_DRAFT,
        public ?CarbonImmutable $scheduledAt = null,
        public ?CarbonImmutable $sentAt = null,
        /**
         * Has an editor released this campaign to the public web archive?
         *
         * `false` is the default and stays the default for every campaign that
         * already exists. A campaign is written for the people who asked for
         * it, and some of them carry prices, a segment's context or an
         * individual address — publishing that to the open web because a
         * package was updated is not a decision an addon may take on someone's
         * behalf. Releasing it is one deliberate act per campaign.
         */
        public bool $inArchive = false,
        /**
         * Which {@see MailClass} this campaign is sent under. `marketing` is
         * the default and the conservative one: a campaign nobody classified
         * is subject to the frequency cap rather than exempt from it. A
         * community digest or an event reminder is opted out by naming its
         * class, never by leaving the field alone.
         */
        public string $mailClass = MailClass::Marketing->value,
    ) {}

    /** The classification the send path acts on. */
    public function mailClass(): MailClass
    {
        return MailClass::fromValue($this->mailClass);
    }

    /**
     * Is there a public web version of this campaign right now?
     *
     * Two conditions, and the second one is not decoration. The flag says an
     * editor wants this campaign public; the status says it has actually gone
     * out. A draft that is already flagged would otherwise be readable on the
     * open web before its own recipients have it, which is the one ordering
     * mistake a newsletter archive can make that cannot be taken back.
     */
    public function isArchived(): bool
    {
        return $this->inArchive && $this->status === self::STATUS_SENT;
    }

    /**
     * Is this campaign running an A/B test?
     *
     * One question, asked in one place, so "what counts as a test" cannot drift
     * between the job that assigns variants and the renderer that acts on them.
     * An empty string is not a variant: a form that posts a blank field must not
     * silently turn a normal campaign into a split test.
     */
    public function hasVariants(): bool
    {
        return $this->variantSubject !== null && trim($this->variantSubject) !== '';
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isSendable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function isEditable(): bool
    {
        return $this->isSendable();
    }

    public static function fromArray(array $data): self
    {
        return new self(
            handle: (string) $data['handle'],
            name: (string) ($data['name'] ?? $data['handle']),
            subject: (string) ($data['subject'] ?? ''),
            variantSubject: $data['variant_subject'] ?? null,
            preheader: $data['preheader'] ?? null,
            fromName: $data['from_name'] ?? null,
            fromEmail: $data['from_email'] ?? null,
            replyTo: $data['reply_to'] ?? null,
            listHandle: $data['list'] ?? null,
            segmentHandle: $data['segment'] ?? null,
            templateHandle: $data['template'] ?? null,
            content: (string) ($data['content'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_DRAFT),
            scheduledAt: isset($data['scheduled_at']) && $data['scheduled_at']
                ? CarbonImmutable::parse($data['scheduled_at'])
                : null,
            sentAt: isset($data['sent_at']) && $data['sent_at']
                ? CarbonImmutable::parse($data['sent_at'])
                : null,
            // Absent means not archived. A YAML file written before this
            // release has no key here, and reading that silence as "publish
            // it" would put every past campaign on the open web the moment the
            // package is updated.
            inArchive: (bool) ($data['archive'] ?? false),
            mailClass: MailClass::fromValue($data['mail_class'] ?? null)->value,
        );
    }

    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'subject' => $this->subject,
            'variant_subject' => $this->variantSubject,
            'preheader' => $this->preheader,
            'from_name' => $this->fromName,
            'from_email' => $this->fromEmail,
            'reply_to' => $this->replyTo,
            'list' => $this->listHandle,
            'segment' => $this->segmentHandle,
            'template' => $this->templateHandle,
            'content' => $this->content,
            'status' => $this->status,
            'scheduled_at' => $this->scheduledAt?->toIso8601String(),
            'sent_at' => $this->sentAt?->toIso8601String(),
            'archive' => $this->inArchive,
            'mail_class' => $this->mailClass,
        ];
    }
}
