<?php

namespace Goldnead\Marketing\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\CampaignRecord;
use Illuminate\Support\Collection;

class EloquentCampaignRepository implements CampaignRepository
{
    use StampsTheBrandItself;

    public function all(): Collection
    {
        return CampaignRecord::query()
            ->orderByRaw('COALESCE(sent_at, scheduled_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CampaignRecord $record) => $this->toEntity($record));
    }

    public function find(string $handle): ?Campaign
    {
        $record = CampaignRecord::query()->where('handle', $handle)->first();

        return $record ? $this->toEntity($record) : null;
    }

    public function save(Campaign $campaign): Campaign
    {
        $this->speichereMitMarke(CampaignRecord::class, $campaign->handle, $this->attributes($campaign));

        return $campaign;
    }

    /**
     * Save, but only while the stored row still has one of these statuses —
     * one UPDATE … WHERE status IN (…), so nothing can claim the row between
     * the check and the write. False when the row moved on (or is gone).
     *
     * For the series sync: a child it loaded may have been claimed by the
     * scheduler meanwhile, and writing it back whole would undo the claim.
     *
     * @param  list<string>  $statuses
     */
    public function saveIfStatusIn(Campaign $campaign, array $statuses): bool
    {
        $record = CampaignRecord::query()->where('handle', $campaign->handle)->first();

        if ($record === null) {
            return false;
        }

        // Fill to let the casts turn arrays and dates into what the columns
        // hold; the dirty set is then exactly the update, in storage form.
        $record->fill($this->attributes($campaign));

        if ($record->usesTimestamps()) {
            $record->updateTimestamps();
        }

        $changes = $record->getDirty();

        if ($changes === []) {
            return in_array($record->getOriginal('status'), $statuses, true);
        }

        return CampaignRecord::query()
            ->whereKey($record->getKey())
            ->whereIn('status', $statuses)
            ->update($changes) > 0;
    }

    /** @return array<string, mixed> */
    protected function attributes(Campaign $campaign): array
    {
        return [
            'name' => $campaign->name,
            'subject' => $campaign->subject,
            'variant_subject' => $campaign->variantSubject,
            'preheader' => $campaign->preheader,
            'from_name' => $campaign->fromName,
            'from_email' => $campaign->fromEmail,
            'reply_to' => $campaign->replyTo,
            'list_handle' => $campaign->listHandle,
            'segment_handle' => $campaign->segmentHandle,
            'template_handle' => $campaign->templateHandle,
            'content' => $campaign->content,
            'status' => $campaign->status,
            'scheduled_at' => $campaign->scheduledAt,
            'sent_at' => $campaign->sentAt,
            'in_archive' => $campaign->inArchive,
            'mail_class' => $campaign->mailClass,
            'ab_share' => $campaign->abShare,
            'series' => $campaign->series,
            'source_key' => $campaign->sourceKey,
            'meta' => $campaign->meta,
        ];
    }

    public function delete(string $handle): bool
    {
        return (bool) CampaignRecord::query()->where('handle', $handle)->delete();
    }

    public function due(\DateTimeInterface $now): Collection
    {
        return CampaignRecord::query()
            ->where('status', Campaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->get()
            ->map(fn (CampaignRecord $record) => $this->toEntity($record));
    }

    protected function toEntity(CampaignRecord $record): Campaign
    {
        return new Campaign(
            handle: $record->handle,
            name: $record->name,
            subject: (string) $record->subject,
            variantSubject: $record->variant_subject,
            preheader: $record->preheader,
            fromName: $record->from_name,
            fromEmail: $record->from_email,
            replyTo: $record->reply_to,
            listHandle: $record->list_handle,
            segmentHandle: $record->segment_handle,
            templateHandle: $record->template_handle,
            content: (string) $record->content,
            status: $record->status,
            scheduledAt: $record->scheduled_at ? CarbonImmutable::parse($record->scheduled_at) : null,
            sentAt: $record->sent_at ? CarbonImmutable::parse($record->sent_at) : null,
            inArchive: (bool) $record->in_archive,
            mailClass: MailClass::fromValue($record->mail_class)->value,
            abShare: (int) ($record->ab_share ?? 0),
            series: $record->series,
            sourceKey: $record->source_key,
            meta: (array) ($record->meta ?? []),
        );
    }
}
