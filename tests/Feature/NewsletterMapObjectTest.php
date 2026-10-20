<?php

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Integrations\Map\NewsletterMapObjects;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;

/**
 * The newsletter as a card on the funnels map.
 *
 * The newsletter is not one object in this addon: it is every campaign of the
 * class `marketing`. The map gets one fixed object, id `newsletter`. It has no
 * switch (enabled is null) and no weekday, because nothing here stores one.
 */
function mapSendMessage(string $campaign, string $sentAt = '-1 day'): void
{
    $subscription = Subscription::create([
        'list_handle' => 'newsletter',
        'email' => uniqid().'@example.com',
        'status' => Subscription::STATUS_SUBSCRIBED,
    ]);

    Message::create([
        'campaign_handle' => $campaign,
        'subscription_id' => $subscription->id,
        'email' => $subscription->email,
        'status' => 'sent',
        'sent_at' => now()->modify($sentAt),
    ]);
}

function mapSaveCampaign(string $handle, string $mailClass): void
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: $handle,
        name: $handle,
        subject: 'Hello',
        listHandle: 'newsletter',
        content: '<p>Hi</p>',
        mailClass: $mailClass,
    ));
}

it('registers the newsletter provider with the map', function (): void {
    expect(app(MapObjectRegistry::class)->has('newsletter'))->toBeTrue();
});

it('offers one fixed newsletter object that links to the campaigns', function (): void {
    $provider = new NewsletterMapObjects;

    $object = $provider->find('newsletter');

    expect($object)->not->toBeNull()
        ->and($object->type)->toBe('newsletter')
        ->and($object->id)->toBe('newsletter')
        ->and($object->label)->toBe('Newsletter')
        ->and($object->cpUrl)->toBe(cp_route('marketing.campaigns.index'))
        ->and($object->enabled)->toBeNull()
        ->and($object->detail)->toContain('running sequence')
        ->and($provider->find('other'))->toBeNull()
        ->and($provider->objects())->toHaveCount(1)
        ->and($provider->objects('zzz'))->toBe([]);
});

it('says the pause in German too', function (): void {
    app()->setLocale('de');

    expect((new NewsletterMapObjects)->find('newsletter')->detail)->toContain('laufenden Strecke');
});

it('counts delivered newsletter mails in the period and leaves other classes out', function (): void {
    mapSaveCampaign('tuesday', 'marketing');
    mapSaveCampaign('reminder', 'reminder');

    mapSendMessage('tuesday');
    mapSendMessage('tuesday');
    mapSendMessage('tuesday', '-90 days');
    mapSendMessage('reminder');

    $metric = (new NewsletterMapObjects)->metric('newsletter', MapPeriod::lastDays(30));

    expect($metric->value)->toBe(2)
        ->and($metric->what)->toBe('Newsletter mails sent');
});

it('has no number without any newsletter campaign, rather than a nought', function (): void {
    expect((new NewsletterMapObjects)->metric('newsletter', MapPeriod::lastDays(30)))->toBeNull()
        ->and((new NewsletterMapObjects)->metric('other', MapPeriod::lastDays(30)))->toBeNull();
});
