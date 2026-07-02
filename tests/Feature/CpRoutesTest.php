<?php

/**
 * Smoke test for the Inertia CP layer: every page renders HTTP 200 with the
 * expected component for an authenticated super user, and no raw
 * `marketing::` translation keys leak into the response.
 */

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()
        ->email('test@example.com')
        ->makeSuper();
    $this->user->save();

    $this->actingAs($this->user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    app(EmailTemplateRepository::class)->save(new EmailTemplate(handle: 'branded', name: 'Branded', html: '{{ content }}'));
    app(CampaignRepository::class)->save(new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hi', listHandle: 'newsletter'));
});

function marketingInertiaComponent($response): ?string
{
    if (! $response->headers->get('X-Inertia')) {
        return null;
    }

    return json_decode($response->getContent(), true)['component'] ?? null;
}

dataset('cp pages', [
    'dashboard' => ['marketing.dashboard', [], 'marketing::Dashboard'],
    'lists index' => ['marketing.lists.index', [], 'marketing::Lists/Index'],
    'lists create' => ['marketing.lists.create', [], 'marketing::Lists/Edit'],
    'lists show' => ['marketing.lists.show', ['newsletter'], 'marketing::Lists/Show'],
    'lists edit' => ['marketing.lists.edit', ['newsletter'], 'marketing::Lists/Edit'],
    'campaigns index' => ['marketing.campaigns.index', [], 'marketing::Campaigns/Index'],
    'campaigns create' => ['marketing.campaigns.create', [], 'marketing::Campaigns/Edit'],
    'campaigns show' => ['marketing.campaigns.show', ['welcome'], 'marketing::Campaigns/Show'],
    'campaigns edit' => ['marketing.campaigns.edit', ['welcome'], 'marketing::Campaigns/Edit'],
    'templates index' => ['marketing.templates.index', [], 'marketing::Templates/Index'],
    'templates create' => ['marketing.templates.create', [], 'marketing::Templates/Edit'],
    'templates edit' => ['marketing.templates.edit', ['branded'], 'marketing::Templates/Edit'],
]);

it('renders the CP page', function (string $route, array $params, string $component): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route($route, $params));

    $response->assertStatus(200);

    expect(marketingInertiaComponent($response))->toBe($component);
    expect($response->getContent())->not->toContain('marketing::nav.');
})->with('cp pages');

it('denies CP access without the marketing permission', function (): void {
    $nobody = User::make()->email('nobody@example.com');
    $nobody->save();

    $this->actingAs($nobody)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.dashboard'))
        ->assertForbidden();
});

it('creates a list through the CP', function (): void {
    $this->post(cp_route('marketing.lists.store'), [
        'name' => 'Product News',
        'handle' => 'product_news',
        'double_opt_in' => true,
    ])->assertRedirect();

    $list = app(MailingListRepository::class)->find('product_news');

    expect($list)->not->toBeNull()
        ->and($list->doubleOptIn)->toBeTrue();
});

it('creates and updates a campaign through the CP', function (): void {
    $this->post(cp_route('marketing.campaigns.store'), [
        'name' => 'Summer Sale',
        'subject' => 'Sale!',
        'list' => 'newsletter',
        'content' => '<p>Hello</p>',
    ])->assertRedirect();

    $campaign = app(CampaignRepository::class)->find('summer_sale');

    expect($campaign)->not->toBeNull();

    $this->patch(cp_route('marketing.campaigns.update', 'summer_sale'), [
        'name' => 'Summer Sale',
        'subject' => 'Big Sale!',
        'list' => 'newsletter',
        'content' => '<p>Hello again</p>',
    ])->assertRedirect();

    expect(app(CampaignRepository::class)->find('summer_sale')->subject)->toBe('Big Sale!');
});

it('adds a subscriber through the CP without double opt-in', function (): void {
    \Illuminate\Support\Facades\Mail::fake();

    $this->post(cp_route('marketing.lists.subscribers.store', 'newsletter'), [
        'email' => 'manual@example.com',
        'first_name' => 'Manual',
    ])->assertRedirect();

    $subscription = \Goldnead\Marketing\Models\Subscription::forList('newsletter')->first();

    expect($subscription->status)->toBe(\Goldnead\Marketing\Models\Subscription::STATUS_SUBSCRIBED);
});

it('serves the campaign HTML preview', function (): void {
    $response = $this->get(cp_route('marketing.campaigns.preview', 'welcome'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/html');
});
