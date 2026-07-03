<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Http\Controllers\Cp\CampaignController;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Graceful degradation when the installed LeadHub predates segments.
 *
 * Marketing must not fatal when the facade root lacks segmentMemberIds() /
 * segments(): the segment filter is ignored (whole-list send) with a single
 * warning, and the CP picker hides itself.
 */

/** A stand-in LeadHub manager WITHOUT any segment methods. */
class LegacyLeadHubStub
{
    public function create(array $attributes): array
    {
        return ['uuid' => 'stub-uuid', 'email' => $attributes['email'] ?? null];
    }

    public function find($id): ?array
    {
        return null;
    }
}

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter', name: 'Newsletter', doubleOptIn: false,
    ));
    $this->list = app(MailingListRepository::class)->find('newsletter');
});

afterEach(function (): void {
    LeadHub::clearResolvedInstances();
});

it('sends to the whole list and logs once when LeadHub lacks segments', function (): void {
    // Build subscriptions with the REAL LeadHub first (subscribe() ingests).
    $service = app(SubscriptionService::class);
    $service->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);
    $service->subscribe($this->list, 'john@example.com', ['first_name' => 'John']);

    // Now swap the facade root so method_exists() sees the legacy API at send time.
    LeadHub::swap(new LegacyLeadHubStub());
    Log::spy();

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'promo',
        name: 'Promo',
        subject: 'Hi',
        listHandle: 'newsletter',
        segmentHandle: 'some-segment',
        content: '<p>Hi</p>',
    ));

    app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('promo'));

    // Segment ignored → whole list received it.
    Mail::assertSent(CampaignMail::class, 2);
    Log::shouldHaveReceived('warning')->once();
});

it('hides the segment picker in the CP when LeadHub lacks segments', function (): void {
    LeadHub::swap(new LegacyLeadHubStub());

    $options = (function () {
        $method = new ReflectionMethod(CampaignController::class, 'segmentOptions');
        $method->setAccessible(true);

        return $method->invoke(app(CampaignController::class));
    })();

    expect($options)->toBe([]);
});
