<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * The "Versandzeit" field is a plain datetime-local, so the zone it is read in
 * must be said by the addon: `marketing.display_timezone` (Europe/Berlin), not
 * `app.timezone`, which stays UTC. What is stored stays UTC.
 */
beforeEach(function (): void {
    Mail::fake();
    CarbonImmutable::setTestNow('2026-06-01 12:00:00');

    $user = User::make()->email('zeit@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'zeit',
        name: 'Zeit',
        subject: 'Hallo',
        listHandle: 'newsletter',
        content: '<p>Hi</p>',
    ));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function scheduledUtc(string $local): string
{
    test()->post(cp_route('marketing.campaigns.schedule', 'zeit'), ['scheduled_at' => $local])->assertSessionHasNoErrors();

    return app(CampaignRepository::class)->find('zeit')->scheduledAt->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');
}

it('reads a winter time in Berlin and stores it as UTC', function (): void {
    config(['app.timezone' => 'UTC']);

    expect(scheduledUtc('2026-10-27T07:00'))->toBe('2026-10-27T06:00:00Z');
});

it('reads a summer time in Berlin and stores it as UTC', function (): void {
    expect(scheduledUtc('2026-07-07T07:00'))->toBe('2026-07-07T05:00:00Z');
});

it('honours a configured display zone', function (): void {
    config(['marketing.display_timezone' => 'America/New_York']);

    expect(scheduledUtc('2026-07-07T07:00'))->toBe('2026-07-07T11:00:00Z');
});

it('falls back to app.timezone when the display zone is empty or invalid', function (): void {
    config(['marketing.display_timezone' => 'Nicht/Existent', 'app.timezone' => 'Asia/Tokyo']);

    expect(scheduledUtc('2026-07-07T07:00'))->toBe('2026-07-06T22:00:00Z');
});

// 12:00 UTC now = 14:00 Berlin. 13:00 Berlin is past, 15:00 Berlin is not.
it('refuses a time that is already past in the zone it was entered in', function (): void {
    $this->post(cp_route('marketing.campaigns.schedule', 'zeit'), ['scheduled_at' => '2026-06-01T13:00'])
        ->assertSessionHasErrors('scheduled_at');
});

it('accepts a time that is still ahead in the zone it was entered in', function (): void {
    expect(scheduledUtc('2026-06-01T15:00'))->toBe('2026-06-01T13:00:00Z');
});

it('treats an empty display zone like an unset one', function (): void {
    config(['marketing.display_timezone' => '', 'app.timezone' => 'UTC']);

    expect(scheduledUtc('2026-07-07T07:00'))->toBe('2026-07-07T07:00:00Z');
});

it('settles the two odd DST wall-clock times deterministically', function (): void {
    CarbonImmutable::setTestNow('2027-01-01 12:00:00');

    // 2027-03-28 02:30 does not exist in Berlin: it moves forward to 03:30 CEST (01:30Z).
    expect(scheduledUtc('2027-03-28T02:30'))->toBe('2027-03-28T01:30:00Z');
    // 2027-10-31 02:30 happens twice: Carbon picks the second one, CET (01:30Z).
    expect(scheduledUtc('2027-10-31T02:30'))->toBe('2027-10-31T01:30:00Z');
});

it('leaves a campaign that is already scheduled where it is', function (): void {
    $campaign = app(CampaignRepository::class)->find('zeit');
    $campaign->status = Campaign::STATUS_SCHEDULED;
    $campaign->scheduledAt = CarbonImmutable::parse('2026-10-27 06:00:00', 'UTC');
    app(CampaignRepository::class)->save($campaign);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.campaigns.edit', 'zeit'))->assertOk();

    expect(app(CampaignRepository::class)->find('zeit')->scheduledAt->setTimezone('UTC')->format('c'))
        ->toBe('2026-10-27T06:00:00+00:00');
});

it('answers garbage with a validation error, not a 500', function (): void {
    $this->post(cp_route('marketing.campaigns.schedule', 'zeit'), ['scheduled_at' => 'not a time'])
        ->assertSessionHasErrors('scheduled_at');
});

it('tells the editor, the listing and the report which zone times are shown in', function (): void {
    foreach ([['marketing.campaigns.edit', 'zeit'], ['marketing.campaigns.index', null], ['marketing.campaigns.show', 'zeit']] as [$route, $handle]) {
        $url = $handle === null ? cp_route($route) : cp_route($route, $handle);
        $props = json_decode($this->withHeaders(['X-Inertia' => 'true'])->get($url)->assertOk()->getContent(), true)['props'];

        expect($props['timezone'])->toBe('Europe/Berlin');
    }
});
