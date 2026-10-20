<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Integrations\Automations\Actions\NewsletterDayGateAction;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * The day gate: a drip mail waits out the days a campaign goes out.
 *
 * Ported from the site that first built it (adriangoldner.com), minus what only
 * concerns that site's welcome strip. `app.timezone` is UTC in these tests, the
 * day boundary is not: it is `marketing.day_gate.timezone`.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    // The flat driver keeps campaigns in a directory that outlives a test.
    $campaigns = app(CampaignRepository::class);
    $campaigns->all()->each(fn (Campaign $campaign) => $campaigns->delete($campaign->handle));

    $this->campaign = function (string $handle, string $status, ?string $scheduledAtUtc = null, ?string $sentAtUtc = null): void {
        app(CampaignRepository::class)->save(new Campaign(
            handle: $handle,
            name: $handle,
            subject: $handle,
            listHandle: 'newsletter',
            status: $status,
            scheduledAt: $scheduledAtUtc ? CarbonImmutable::parse($scheduledAtUtc, 'UTC') : null,
            sentAt: $sentAtUtc ? CarbonImmutable::parse($sentAtUtc, 'UTC') : null,
        ));
    };

    $this->gate = fn (bool $testMode = false): ActionResult => (new NewsletterDayGateAction)
        ->execute(AutomationContext::make([], $testMode), []);
});

it('is registered as a built-in node under the handle sites already use', function (): void {
    $automations = app('automations');

    expect(NewsletterDayGateAction::handle())->toBe('newsletter_day_gate')
        ->and($automations->actions()->instance('newsletter_day_gate'))->toBeInstanceOf(NewsletterDayGateAction::class)
        ->and($automations->isBuiltIn('newsletter_day_gate'))->toBeTrue();
});

it('is evaluated again after the wait instead of being walked past', function (): void {
    expect(NewsletterDayGateAction::reexecuteOnResume())->toBeTrue();
});

it('passes when no campaign lies on the day', function (): void {
    $this->travelTo('2026-10-13 10:00:00');

    $result = ($this->gate)();

    expect($result->status)->toBe('success')
        ->and($result->output['blocked'])->toBeFalse();
});

it('waits when a campaign is scheduled for the day', function (): void {
    $this->travelTo('2026-10-13 05:00:00');
    ($this->campaign)('dienstag', Campaign::STATUS_SCHEDULED, scheduledAtUtc: '2026-10-13 07:00:00');

    $result = ($this->gate)();

    expect($result->status)->toBe('waiting')
        ->and($result->output['blocked'])->toBeTrue()
        ->and($result->output['campaign'])->toBe('dienstag');
});

it('waits when a campaign was already sent on the day', function (): void {
    $this->travelTo('2026-10-13 10:00:00');
    ($this->campaign)('schon-raus', Campaign::STATUS_SENT, sentAtUtc: '2026-10-13 05:00:00');

    expect(($this->gate)()->status)->toBe('waiting');
});

it('also waits while the campaign is still going out', function (): void {
    $this->travelTo('2026-10-13 10:00:00');
    ($this->campaign)('unterwegs', Campaign::STATUS_SENDING, sentAtUtc: '2026-10-13 08:00:00');

    expect(($this->gate)()->status)->toBe('waiting');
});

it('is not blocked by drafts or by campaigns on other days', function (): void {
    $this->travelTo('2026-10-13 10:00:00');
    ($this->campaign)('entwurf', Campaign::STATUS_DRAFT, scheduledAtUtc: '2026-10-13 07:00:00');
    ($this->campaign)('gestern', Campaign::STATUS_SENT, sentAtUtc: '2026-10-12 07:00:00');
    ($this->campaign)('morgen', Campaign::STATUS_SCHEDULED, scheduledAtUtc: '2026-10-14 07:00:00');

    expect(($this->gate)()->status)->toBe('success');
});

it('draws the day boundary in Berlin, not in UTC', function (): void {
    // Monday 23:30 UTC is already Tuesday 01:30 in Berlin (CEST, UTC+2).
    ($this->campaign)('grenze', Campaign::STATUS_SENT, sentAtUtc: '2026-10-12 23:30:00');

    // Tuesday in Berlin (10:00 UTC): the campaign counts as Tuesday.
    $this->travelTo('2026-10-13 10:00:00');
    $blocked = ($this->gate)();

    expect($blocked->status)->toBe('waiting')
        ->and($blocked->output['campaign'])->toBe('grenze')
        // The same time tomorrow, handed over as UTC.
        ->and(CarbonImmutable::parse($blocked->waitUntil['due_at'])->toIso8601String())->toBe('2026-10-14T10:00:00+00:00');

    // Monday in Berlin (12:00 UTC): no hit.
    $this->travelTo('2026-10-12 12:00:00');

    expect(($this->gate)()->status)->toBe('success');
});

it('takes the day boundary from marketing.day_gate.timezone', function (): void {
    // Tuesday 01:30 in Berlin, but still Monday evening in New York (UTC-4).
    ($this->campaign)('grenze', Campaign::STATUS_SENT, sentAtUtc: '2026-10-12 23:30:00');
    $this->travelTo('2026-10-13 10:00:00');

    expect(($this->gate)()->status)->toBe('waiting');

    config()->set('marketing.day_gate.timezone', 'America/New_York');

    // 10:00 UTC is 06:00 on Tuesday in New York; the campaign lies on Monday there.
    expect(($this->gate)()->status)->toBe('success');
});

it('falls back to Berlin for a timezone nobody can resolve', function (): void {
    ($this->campaign)('grenze', Campaign::STATUS_SENT, sentAtUtc: '2026-10-12 23:30:00');
    $this->travelTo('2026-10-13 10:00:00');

    config()->set('marketing.day_gate.timezone', 'Mars/Olympus');

    expect(($this->gate)()->status)->toBe('waiting');
});

it('never blocks a test run', function (): void {
    $this->travelTo('2026-10-13 10:00:00');
    ($this->campaign)('heute', Campaign::STATUS_SENT, sentAtUtc: '2026-10-13 07:00:00');

    expect(($this->gate)(testMode: true)->status)->toBe('success');
});

it('names the campaign as the reason when a campaign holds it back', function (): void {
    $this->travelTo('2026-10-13 10:00:00');
    ($this->campaign)('heute', Campaign::STATUS_SENT, sentAtUtc: '2026-10-13 07:00:00');

    $result = ($this->gate)();

    expect($result->output['blocked'])->toBeTrue()
        ->and($result->output['reason'])->toBe('campaign');
});

describe('one mail per day', function (): void {
    beforeEach(function (): void {
        $this->gateWith = fn (array $config, array $data = [], bool $testMode = false): ActionResult => (new NewsletterDayGateAction)
            ->execute(AutomationContext::make($data, $testMode), $config);

        $this->mailed = function (string $email, string $sentAtUtc): void {
            $subscription = Subscription::firstOrCreate(
                ['list_handle' => 'newsletter', 'email' => $email],
                ['status' => Subscription::STATUS_SUBSCRIBED],
            );

            Message::create([
                'subscription_id' => $subscription->id,
                'email' => $email,
                'status' => Message::STATUS_SENT,
                'sent_at' => $sentAtUtc,
                'campaign_handle' => 'welcome-1',
            ]);
        };

        $this->subscriber = ['subscriber' => ['email' => 'maria@example.com']];
    });

    it('holds the drip mail when the address already received a marketing mail today', function (): void {
        $this->travelTo('2026-10-13 10:00:00');
        ($this->mailed)('maria@example.com', '2026-10-13 07:00:00');

        $result = ($this->gateWith)(['one_per_day' => true], $this->subscriber);

        expect($result->status)->toBe('waiting')
            ->and($result->output['blocked'])->toBeTrue()
            ->and($result->output['reason'])->toBe('mailed_today')
            ->and(CarbonImmutable::parse($result->waitUntil['due_at'])->toIso8601String())->toBe('2026-10-14T10:00:00+00:00');
    });

    it('does not hold for a mail sent on another day', function (): void {
        $this->travelTo('2026-10-13 10:00:00');
        ($this->mailed)('maria@example.com', '2026-10-12 07:00:00');

        expect(($this->gateWith)(['one_per_day' => true], $this->subscriber)->status)->toBe('success');
    });

    it('counts the day in Berlin, not in UTC', function (): void {
        // 23:30 UTC on the 12th is 01:30 on the 13th in Berlin.
        ($this->mailed)('maria@example.com', '2026-10-12 23:30:00');
        $this->travelTo('2026-10-13 10:00:00');

        expect(($this->gateWith)(['one_per_day' => true], $this->subscriber)->status)->toBe('waiting');
    });

    it('does nothing unless the switch is on', function (): void {
        $this->travelTo('2026-10-13 10:00:00');
        ($this->mailed)('maria@example.com', '2026-10-13 07:00:00');

        $off = ($this->gateWith)([], $this->subscriber);

        expect($off->status)->toBe('success')
            ->and($off->output)->toBe(['blocked' => false])
            ->and(($this->gateWith)(['one_per_day' => false], $this->subscriber)->status)->toBe('success');
    });

    it('reads the configured address before the one the run is about', function (): void {
        $this->travelTo('2026-10-13 10:00:00');
        ($this->mailed)('jonas@example.com', '2026-10-13 07:00:00');

        expect(($this->gateWith)(['one_per_day' => true, 'email' => 'jonas@example.com'], $this->subscriber)->status)->toBe('waiting')
            ->and(($this->gateWith)(['one_per_day' => true], $this->subscriber)->status)->toBe('success');
    });

    it('still reports a campaign as the reason when both hold', function (): void {
        $this->travelTo('2026-10-13 10:00:00');
        ($this->campaign)('heute', Campaign::STATUS_SENT, sentAtUtc: '2026-10-13 07:00:00');
        ($this->mailed)('maria@example.com', '2026-10-13 07:00:00');

        expect(($this->gateWith)(['one_per_day' => true], $this->subscriber)->output['reason'])->toBe('campaign');
    });

    it('fails without any address instead of letting the mail through', function (): void {
        $this->travelTo('2026-10-13 10:00:00');

        $result = ($this->gateWith)(['one_per_day' => true]);

        expect($result->status)->toBe('failed')
            ->and($result->output['missing_data_reference'])->toBe('email');
    });

    it('never blocks a test run', function (): void {
        $this->travelTo('2026-10-13 10:00:00');
        ($this->mailed)('maria@example.com', '2026-10-13 07:00:00');

        expect(($this->gateWith)(['one_per_day' => true], $this->subscriber, testMode: true)->status)->toBe('success');
    });
});
