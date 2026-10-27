<?php

namespace Goldnead\Marketing\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Models\AbDecision;
use Goldnead\Marketing\Models\Message;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The winner send of an A/B test: who is in the test, when it is over, who won.
 *
 * A campaign with a variant subject and `ab_share` 10-50 goes out in two
 * passes. The first sends to the test group only (about `ab_share` percent of
 * the audience, split half and half between the subjects), the second, after
 * the wait, sends the winning subject to everybody else. The state between
 * the two lives in `meta['ab']` on the campaign, so a restarted worker, a
 * second `marketing:send-ab-winners` run or a retried job finds the same
 * facts and cannot start the second pass twice.
 *
 * **The rules, because they are product decisions and were assumed:**
 *
 * - The criterion is the open rate among delivered mails of the test group,
 *   people not machines ({@see MessageEngagement}: a click counts as an open,
 *   a machine's open alone does not).
 * - The test is over `marketing.ab.wait_minutes` (default 240) after the last
 *   test mail was resolved.
 * - A tie, or fewer than `marketing.ab.min_per_variant` delivered mails in
 *   either variant, goes to variant A. The reason is stored, never implied.
 *
 * Membership of the test group is a pure function of (brand, campaign,
 * recipient uuid), like {@see VariantAssigner}: it survives a retry, a restore
 * and a row that was deleted and rebuilt.
 */
class AbTest
{
    public const PHASE_TESTING = 'testing';

    public const PHASE_WINNER = 'winner';

    public const REASON_RATE = 'higher_open_rate';

    public const REASON_TIE = 'tie';

    public const REASON_SAMPLE = 'sample_too_small';

    public function __construct(protected MessageEngagement $engagement) {}

    public function waitMinutes(): int
    {
        return max(0, (int) config('marketing.ab.wait_minutes', 240));
    }

    public function minPerVariant(): int
    {
        return max(1, (int) config('marketing.ab.min_per_variant', 10));
    }

    /** @return array<string, mixed> */
    public function state(Campaign $campaign): array
    {
        return (array) ($campaign->meta['ab'] ?? []);
    }

    /** Is this campaign between the test pass and the winner pass? */
    public function isTesting(Campaign $campaign): bool
    {
        return $campaign->hasTestShare() && ($this->state($campaign)['phase'] ?? null) === self::PHASE_TESTING;
    }

    public function winnerDecided(Campaign $campaign): bool
    {
        return $campaign->hasTestShare() && ($this->state($campaign)['phase'] ?? null) === self::PHASE_WINNER;
    }

    public function inTestGroup(string $campaignHandle, string $recipientKey, ?int $brandId, int $share): bool
    {
        $digest = hash('sha256', implode("\0", ['ab-test', (string) $brandId, $campaignHandle, $recipientKey]));

        return ((int) hexdec(substr($digest, 0, 8))) % 100 < $share;
    }

    /** The test pass starts: remember it, once. A restart keeps the first state. */
    public function begin(CampaignRepository $campaigns, Campaign $campaign): void
    {
        $this->write($campaigns, $campaign, function (array $ab): array {
            return $ab === [] ? [
                'phase' => self::PHASE_TESTING,
                'test_started_at' => CarbonImmutable::now()->toIso8601String(),
                'snapshot_done' => false,
            ] : $ab;
        });
    }

    /** The test pass has written every row it will write. */
    public function snapshotDone(CampaignRepository $campaigns, Campaign $campaign): void
    {
        $this->write($campaigns, $campaign, fn (array $ab) => ['snapshot_done' => true] + $ab);
        $this->testFinished($campaigns, $campaign);
    }

    /**
     * Note the moment nothing of the test is pending any more. Called by the
     * snapshot and by every send job that resolves a message; does nothing
     * until the snapshot is complete (a sync queue resolves the first message
     * long before the audience is written) or when already noted.
     */
    public function testFinished(CampaignRepository $campaigns, Campaign $campaign): void
    {
        if (Message::forCampaign($campaign->handle)->pending()->exists()) {
            return;
        }

        $this->write($campaigns, $campaign, function (array $ab) use ($campaign): array {
            if (($ab['phase'] ?? null) !== self::PHASE_TESTING || ! ($ab['snapshot_done'] ?? false) || isset($ab['test_finished_at'])) {
                return $ab;
            }

            $ab['test_finished_at'] = CarbonImmutable::now()->toIso8601String();
            $ab['test_last_message_id'] = (int) Message::forCampaign($campaign->handle)->max('id');

            return $ab;
        });
    }

    public function isDue(Campaign $campaign): bool
    {
        $ab = $this->state($campaign);

        if (! $this->isTesting($campaign) || empty($ab['test_finished_at'])) {
            return false;
        }

        return CarbonImmutable::parse($ab['test_finished_at'])->addMinutes($this->waitMinutes())->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /**
     * Pick the winner and claim the decision.
     *
     * The claim is one insert into `marketing_ab_decisions` with a unique key:
     * of any number of callers (two hosts, two overlapping runs, a cache that
     * cannot lock) exactly one inserts, the others hit the index. The caller
     * that wins gets `claimed => true` and is the only one that may queue the
     * winner pass. A loser reads the winner's row, and repairs the campaign's
     * `meta.ab` from it when the winner died before writing it.
     *
     * @return array<string, mixed> the state with `winner`, `reason`, `results`,
     *                              plus `claimed` (this call made the decision)
     */
    public function decide(CampaignRepository $campaigns, Campaign $campaign): array
    {
        $fresh = $campaigns->find($campaign->handle) ?? $campaign;
        $ab = $this->state($fresh);

        if (($ab['phase'] ?? null) === self::PHASE_WINNER) {
            return $ab + ['claimed' => false];
        }

        [$winner, $reason, $results] = $this->pick($fresh, $ab);

        try {
            $row = AbDecision::create([
                'claim_key' => $this->claimKey($fresh->handle),
                'campaign_handle' => $fresh->handle,
                'winner' => $winner,
                'reason' => $reason,
                'results' => $results,
                'decided_at' => CarbonImmutable::now(),
            ]);
            $claimed = true;
        } catch (UniqueConstraintViolationException) {
            $row = $this->decision($fresh->handle);
            $claimed = false;

            if ($row === null) {
                return $ab + ['claimed' => false];
            }
        }

        $this->write($campaigns, $fresh, fn (array $ab) => [
            'phase' => self::PHASE_WINNER,
            'winner' => $row->winner,
            'reason' => $row->reason,
            'results' => (array) $row->results,
            'decided_at' => $row->decided_at->toIso8601String(),
        ] + $ab);

        return $this->state($campaigns->find($campaign->handle) ?? $fresh) + ['claimed' => $claimed];
    }

    public function decision(string $campaignHandle): ?AbDecision
    {
        return AbDecision::query()->where('claim_key', $this->claimKey($campaignHandle))->first();
    }

    /**
     * Stamp the claim as queued now, only if nobody else did since `$seen`.
     * One conditional UPDATE: the row count says whether this caller won.
     */
    public function markDispatched(AbDecision $row, ?CarbonInterface $seen): bool
    {
        $query = AbDecision::query()->whereKey($row->getKey());

        $seen === null
            ? $query->whereNull('dispatched_at')
            : $query->where('dispatched_at', $seen);

        return $query->update(['dispatched_at' => CarbonImmutable::now()]) === 1;
    }

    /** The winner pass is alive: push the resume window out. */
    public function heartbeat(string $campaignHandle): void
    {
        AbDecision::query()->where('claim_key', $this->claimKey($campaignHandle))->update(['dispatched_at' => CarbonImmutable::now()]);
    }

    public function resumeAfterMinutes(): int
    {
        return max(1, (int) config('marketing.ab.resume_after_minutes', 30));
    }

    /**
     * A decided test whose winner pass never wrote its audience to the end and
     * was last queued longer ago than the resume window: it was lost (a
     * dispatch that never reached a worker, a job that failed for good) and
     * is queued again. Safe to repeat — the pass is idempotent.
     */
    public function needsResume(Campaign $campaign): bool
    {
        $ab = $this->state($campaign);

        if (! $this->winnerDecided($campaign) || ($ab['winner_pass_done'] ?? false)) {
            return false;
        }

        $row = $this->decision($campaign->handle);

        if ($row === null) {
            return false;
        }

        $last = $row->dispatched_at ?? $row->decided_at;

        return $last->copy()->addMinutes($this->resumeAfterMinutes())->lessThanOrEqualTo(CarbonImmutable::now());
    }

    /** The winner pass has written every row it will write. */
    public function winnerPassDone(CampaignRepository $campaigns, Campaign $campaign): void
    {
        $this->write($campaigns, $campaign, fn (array $ab) => ['winner_pass_done' => true] + $ab);
    }

    public function claimKey(string $campaignHandle): string
    {
        $brand = app()->bound('brand-context') ? app('brand-context')->currentId() : null;

        return $brand.'|'.$campaignHandle;
    }

    /**
     * @param  array<string, mixed>  $ab
     * @return array{0: string, 1: string, 2: array<string, array{delivered: int, opened: int}>}
     */
    protected function pick(Campaign $campaign, array $ab): array
    {
        $lastId = (int) ($ab['test_last_message_id'] ?? Message::forCampaign($campaign->handle)->max('id'));
        $results = [];

        foreach (VariantAssigner::variants() as $variant) {
            $uuids = Message::forCampaign($campaign->handle)->forVariant($variant)->where('id', '<=', $lastId)->pluck('uuid')->all();
            $figures = $this->engagement->forMessages($uuids);
            $results[$variant] = ['delivered' => $figures['sent'], 'opened' => $figures['opened']];
        }

        $a = $results[VariantAssigner::VARIANT_A];
        $b = $results[VariantAssigner::VARIANT_B];

        if (min($a['delivered'], $b['delivered']) < $this->minPerVariant()) {
            return [VariantAssigner::VARIANT_A, self::REASON_SAMPLE, $results];
        }

        // Rates compared by cross-multiplication: no float equality.
        $left = $b['opened'] * $a['delivered'];
        $right = $a['opened'] * $b['delivered'];

        return match (true) {
            $left > $right => [VariantAssigner::VARIANT_B, self::REASON_RATE, $results],
            $left < $right => [VariantAssigner::VARIANT_A, self::REASON_RATE, $results],
            default => [VariantAssigner::VARIANT_A, self::REASON_TIE, $results],
        };
    }

    /**
     * Read-modify-write on the campaign as stored now, never on the copy a job
     * has carried around: the status may have moved meanwhile, and saving a
     * stale copy would put it back.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    protected function write(CampaignRepository $campaigns, Campaign $campaign, callable $change): void
    {
        $fresh = $campaigns->find($campaign->handle) ?? $campaign;
        $meta = $fresh->meta;
        $new = $change((array) ($meta['ab'] ?? []));

        if ($new === ($meta['ab'] ?? [])) {
            return;
        }

        $meta['ab'] = $new;
        $fresh->meta = $meta;
        $campaigns->save($fresh);
    }
}
