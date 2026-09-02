<?php

/**
 * The A/B test share on a campaign: stored and validated, not yet acted on.
 *
 * 0 is the plain half-and-half split every campaign does today. 10–50 is the
 * share a winner send would test on, and that send is Phase 2 — so what is
 * held here is that the value survives both drivers and that nothing outside
 * the range, or without a second subject, gets stored.
 */

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('test@example.com')->makeSuper();
    $this->user->save();

    $this->actingAs($this->user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function abCampaignPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Frühjahr',
        'handle' => 'spring',
        'subject' => 'Betreff A',
        'variant_subject' => 'Betreff B',
        'ab_share' => 30,
        'list' => 'newsletter',
        'content' => '<p>Hallo</p>',
    ], $overrides);
}

it('stores the share next to the variant subject', function (): void {
    $this->post(cp_route('marketing.campaigns.store'), abCampaignPayload())
        ->assertSessionHasNoErrors();

    $campaign = app(CampaignRepository::class)->find('spring');

    expect($campaign->abShare)->toBe(30)
        ->and($campaign->hasTestShare())->toBeTrue()
        ->and($campaign->toArray()['ab_share'])->toBe(30);
});

it('treats a missing share as the plain split', function (): void {
    $payload = abCampaignPayload();
    unset($payload['ab_share']);

    $this->post(cp_route('marketing.campaigns.store'), $payload)->assertSessionHasNoErrors();

    $campaign = app(CampaignRepository::class)->find('spring');

    expect($campaign->abShare)->toBe(0)
        ->and($campaign->hasVariants())->toBeTrue()
        ->and($campaign->hasTestShare())->toBeFalse();
});

it('refuses a share outside 0 or 10 to 50', function (int $share): void {
    $this->from(cp_route('marketing.campaigns.create'))
        ->post(cp_route('marketing.campaigns.store'), abCampaignPayload(['ab_share' => $share]))
        ->assertSessionHasErrors('ab_share');

    expect(app(CampaignRepository::class)->find('spring'))->toBeNull();
})->with([5, 9, 51, 100]);

it('accepts the edges of the range', function (int $share): void {
    $this->post(cp_route('marketing.campaigns.store'), abCampaignPayload(['ab_share' => $share]))
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('spring')->abShare)->toBe($share);
})->with([0, 10, 50]);

it('refuses a share without a second subject', function (): void {
    $this->from(cp_route('marketing.campaigns.create'))
        ->post(cp_route('marketing.campaigns.store'), abCampaignPayload(['variant_subject' => null, 'ab_share' => 20]))
        ->assertSessionHasErrors('ab_share');
});

it('changes the share on update', function (): void {
    $this->post(cp_route('marketing.campaigns.store'), abCampaignPayload());

    $this->patch(cp_route('marketing.campaigns.update', 'spring'), abCampaignPayload(['ab_share' => 10]))
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('spring')->abShare)->toBe(10);
});

it('round-trips through the eloquent driver', function (): void {
    config()->set('marketing.storage.driver', 'eloquent');

    $repository = app(CampaignRepository::class);

    $repository->save(new Campaign(
        handle: 'spring',
        name: 'Frühjahr',
        subject: 'A',
        variantSubject: 'B',
        listHandle: 'newsletter',
        abShare: 40,
    ));

    expect($repository->find('spring')->abShare)->toBe(40);
});
