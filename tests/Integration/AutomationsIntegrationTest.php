<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    if (! class_exists(\Goldnead\StatamicAutomations\Facades\Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }
});

it('registers the marketing triggers and actions as built-in automation nodes', function (): void {
    $automations = app('automations');

    foreach (['marketing.subscribed', 'marketing.unsubscribed', 'marketing.campaign_sent'] as $handle) {
        expect($automations->triggers()->instance($handle))->not->toBeNull()
            ->and($automations->isBuiltIn($handle))->toBeTrue();
    }

    foreach (['marketing.subscribe', 'marketing.unsubscribe', 'marketing.send_campaign'] as $handle) {
        expect($automations->actions()->instance($handle))->not->toBeNull()
            ->and($automations->isBuiltIn($handle))->toBeTrue();
    }
});

it('contributes the marketing templates to the automations catalog', function (): void {
    $registry = app(\Goldnead\StatamicAutomations\Templates\TemplateRegistry::class);

    foreach ([
        'marketing_welcome_series',
        'marketing_form_to_newsletter',
        'marketing_qualified_lead_to_newsletter',
        'marketing_campaign_sent_notification',
        'marketing_unsubscribe_alert',
    ] as $handle) {
        expect($registry->get($handle))->not->toBeNull();
    }
});

it('runs an automation when a subscriber confirms', function (): void {
    Mail::fake();

    // An enabled automation: marketing.subscribed trigger -> log entry action.
    $automation = \Goldnead\StatamicAutomations\Models\Automation::create([
        'name' => 'On subscribe',
        'handle' => 'on_subscribe',
        'enabled' => true,
    ]);

    $trigger = $automation->nodes()->create([
        'node_key' => 'trigger',
        'type' => 'marketing.subscribed',
        'position_x' => 0,
        'position_y' => 0,
        'config' => ['list' => 'newsletter'],
    ]);

    $action = $automation->nodes()->create([
        'node_key' => 'log',
        'type' => 'add_log_entry',
        'position_x' => 250,
        'position_y' => 0,
        'config' => ['message' => 'Subscribed: {{ subscriber.email }}'],
    ]);

    $automation->edges()->create([
        'from_node_key' => 'trigger',
        'to_node_key' => 'log',
        'from_output' => 'default',
    ]);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'run@example.com',
    );

    // Sync queue: the run executed inline.
    $run = \Goldnead\StatamicAutomations\Models\AutomationRun::query()
        ->where('automation_id', $automation->id)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(\Goldnead\StatamicAutomations\Models\AutomationRun::STATUS_SUCCESS);
});

it('executes the marketing.subscribe action against a real list', function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    $action = app('automations')->actions()->instance('marketing.subscribe');
    $context = \Goldnead\StatamicAutomations\Context\AutomationContext::make([]);

    $result = $action->execute($context, [
        'list' => 'newsletter',
        'email' => 'action@example.com',
        'first_name' => 'Auto',
    ]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['status'])->toBe('subscribed');

    expect(\Goldnead\Marketing\Models\Subscription::forList('newsletter')->where('email', 'action@example.com')->exists())->toBeTrue();
});
