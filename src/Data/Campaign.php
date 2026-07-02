<?php

namespace Goldnead\Marketing\Data;

use Carbon\CarbonImmutable;

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
        public ?string $preheader = null,
        public ?string $fromName = null,
        public ?string $fromEmail = null,
        public ?string $replyTo = null,
        public ?string $listHandle = null,
        public ?string $templateHandle = null,
        public string $content = '',
        public string $status = self::STATUS_DRAFT,
        public ?CarbonImmutable $scheduledAt = null,
        public ?CarbonImmutable $sentAt = null,
    ) {
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
            preheader: $data['preheader'] ?? null,
            fromName: $data['from_name'] ?? null,
            fromEmail: $data['from_email'] ?? null,
            replyTo: $data['reply_to'] ?? null,
            listHandle: $data['list'] ?? null,
            templateHandle: $data['template'] ?? null,
            content: (string) ($data['content'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_DRAFT),
            scheduledAt: isset($data['scheduled_at']) && $data['scheduled_at']
                ? CarbonImmutable::parse($data['scheduled_at'])
                : null,
            sentAt: isset($data['sent_at']) && $data['sent_at']
                ? CarbonImmutable::parse($data['sent_at'])
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'name' => $this->name,
            'subject' => $this->subject,
            'preheader' => $this->preheader,
            'from_name' => $this->fromName,
            'from_email' => $this->fromEmail,
            'reply_to' => $this->replyTo,
            'list' => $this->listHandle,
            'template' => $this->templateHandle,
            'content' => $this->content,
            'status' => $this->status,
            'scheduled_at' => $this->scheduledAt?->toIso8601String(),
            'sent_at' => $this->sentAt?->toIso8601String(),
        ];
    }
}
