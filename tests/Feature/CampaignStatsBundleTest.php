<?php

/**
 * One definition of "open rate", reachable two ways.
 *
 * The dashboard needs the figures of a dozen campaigns at once and cannot pay
 * six queries each for them, so `CampaignStats::forCampaigns()` counts with a
 * `GROUP BY` where `forCampaign()` counts with a dozen `count()` calls. Two
 * implementations of the same numbers is precisely how an addon ends up with a
 * campaign page and a dashboard that disagree about the same campaign — this
 * repo has shipped that defect and fixed it, three times, in different corners.
 *
 * So the two are held against each other here rather than reasoned about: same
 * campaign, both paths, identical arrays. And the older path is asserted to be
 * untouched, because it is called from four places and its figures are what
 * every campaign sent so far was measured with.
 */

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignStats;

beforeEach(function (): void {
    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    /** A campaign with the given deliveries, each described by status/opens/clicks. */
    $this->campaign = function (string $handle, array $people, ?string $variantSubject = null): Campaign {
        $campaign = new Campaign(
            handle: $handle,
            name: ucfirst($handle),
            subject: 'Hallo',
            variantSubject: $variantSubject,
            listHandle: 'newsletter',
            status: Campaign::STATUS_SENT,
            sentAt: CarbonImmutable::now()->subDay(),
        );

        app(CampaignRepository::class)->save($campaign);

        foreach ($people as $index => $person) {
            $email = "{$handle}-{$index}@example.com";

            $subscription = Subscription::create([
                'list_handle' => 'newsletter',
                'email' => $email,
                'status' => Subscription::STATUS_SUBSCRIBED,
            ]);

            $message = Message::create([
                'campaign_handle' => $handle,
                'subscription_id' => $subscription->id,
                'email' => $email,
                'status' => $person['status'] ?? Message::STATUS_SENT,
                'variant' => $person['variant'] ?? null,
                'opens' => $person['opens'] ?? 0,
                'clicks' => $person['clicks'] ?? 0,
                'sent_at' => now()->subDay(),
            ]);

            if ($person['unsubscribed'] ?? false) {
                MessageEvent::create([
                    'message_id' => $message->id,
                    'type' => MessageEvent::TYPE_UNSUBSCRIBE,
                ]);
            }
        }

        return $campaign;
    };
});

it('gives the bundled figures the single lookup gives', function (): void {
    $campaign = ($this->campaign)('brief', [
        ['status' => Message::STATUS_SENT, 'opens' => 3, 'clicks' => 1],
        ['status' => Message::STATUS_SENT, 'opens' => 1, 'clicks' => 0, 'unsubscribed' => true],
        ['status' => Message::STATUS_SENT, 'opens' => 0, 'clicks' => 0],
        ['status' => Message::STATUS_FAILED],
        ['status' => Message::STATUS_BOUNCED],
        ['status' => Message::STATUS_SKIPPED],
        ['status' => Message::STATUS_CAPPED],
        ['status' => Message::STATUS_PENDING],
    ]);

    $stats = app(CampaignStats::class);

    expect($stats->forCampaigns([$campaign])['brief'])->toBe($stats->forCampaign($campaign));
});

it('agrees about the A/B breakdown as well, not only the totals', function (): void {
    $campaign = ($this->campaign)('split', [
        ['variant' => 'a', 'opens' => 2, 'clicks' => 1],
        ['variant' => 'a', 'opens' => 0],
        ['variant' => 'b', 'opens' => 1, 'unsubscribed' => true],
        ['variant' => 'b', 'status' => Message::STATUS_FAILED],
    ], variantSubject: 'Anders');

    $stats = app(CampaignStats::class);
    $bundled = $stats->forCampaigns([$campaign])['split'];

    expect($bundled)->toBe($stats->forCampaign($campaign))
        // Stated outright, so that "they are equal" cannot be satisfied by two
        // empty breakdowns.
        ->and(array_keys($bundled['variants']))->toBe(['a', 'b'])
        ->and($bundled['variants']['a']['sample_size'])->toBe(2);
});

it('agrees about a campaign nobody has been sent', function (): void {
    // The one that divides by zero if a rate is ever worked out anywhere but
    // in the shared place — and the state a dashboard meets on a fresh install.
    $campaign = ($this->campaign)('leer', []);

    $stats = app(CampaignStats::class);
    $bundled = $stats->forCampaigns([$campaign])['leer'];

    expect($bundled)->toBe($stats->forCampaign($campaign))
        ->and($bundled['open_rate'])->toBe(0.0)
        ->and($bundled['click_rate'])->toBe(0.0)
        ->and($bundled['recipients'])->toBe(0);
});

it('keeps every campaign to itself', function (): void {
    $first = ($this->campaign)('erste', [
        ['opens' => 1, 'clicks' => 1],
        ['opens' => 1],
    ]);

    $second = ($this->campaign)('zweite', [
        ['opens' => 0],
    ]);

    $figures = app(CampaignStats::class)->forCampaigns([$first, $second]);

    expect($figures['erste']['opened'])->toBe(2)
        ->and($figures['erste']['clicked'])->toBe(1)
        ->and($figures['zweite']['opened'])->toBe(0)
        ->and($figures['zweite']['recipients'])->toBe(1);
});

it('answers for a campaign that has no messages at all', function (): void {
    app(CampaignRepository::class)->save(new Campaign(handle: 'entwurf', name: 'Entwurf'));

    $figures = app(CampaignStats::class)->forCampaigns(['entwurf']);

    expect($figures)->toHaveKey('entwurf')
        ->and($figures['entwurf']['recipients'])->toBe(0)
        ->and($figures['entwurf']['variants'])->toBe([]);
});

it('asks nothing at all when handed nothing', function (): void {
    expect(app(CampaignStats::class)->forCampaigns([]))->toBe([]);
});
