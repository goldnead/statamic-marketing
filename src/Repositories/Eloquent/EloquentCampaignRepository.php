<?php

namespace Goldnead\Marketing\Repositories\Eloquent;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\CampaignRecord;
use Illuminate\Support\Collection;

class EloquentCampaignRepository implements CampaignRepository
{
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
        CampaignRecord::query()->updateOrCreate(
            ['handle' => $campaign->handle],
            [
                'name' => $campaign->name,
                'subject' => $campaign->subject,
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
            ],
        );

        return $campaign;
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
        );
    }
}
