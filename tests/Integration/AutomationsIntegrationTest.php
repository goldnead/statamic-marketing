<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\Actions\SendMarketingEmailAction;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\MailLogEntry;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
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
    $registry = app(TemplateRegistry::class);

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
    $automation = Automation::create([
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
    $run = AutomationRun::query()
        ->where('automation_id', $automation->id)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AutomationRun::STATUS_SUCCESS);
});

it('executes the marketing.subscribe action against a real list', function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    $action = app('automations')->actions()->instance('marketing.subscribe');
    $context = AutomationContext::make([]);

    $result = $action->execute($context, [
        'list' => 'newsletter',
        'email' => 'action@example.com',
        'first_name' => 'Auto',
    ]);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['status'])->toBe('subscribed');

    expect(Subscription::forList('newsletter')->where('email', 'action@example.com')->exists())->toBeTrue();
});

/*
 * The marketing send node — the one node in the family that has to live on
 * this side of the boundary, and therefore the one whose registration nothing
 * on the automations side can prove.
 */

it('contributes the marketing send node to the automations builder', function (): void {
    $automations = app('automations');

    expect($automations->actions()->instance('marketing.send_email'))->not->toBeNull()
        // Built-in, not a customer's custom action: a marketing feature may not
        // depend on which edition of the orchestrator is licensed.
        ->and($automations->isBuiltIn('marketing.send_email'))->toBeTrue();

    $described = $automations->nodes()->describe('marketing.send_email');

    expect($described['kind'])->toBe('action')
        ->and($described['group'])->toBe('Marketing')
        ->and(collect($described['schema'])->pluck('handle')->all())->toBe(['campaign', 'list', 'to']);
});

it('sends a campaign to the contact in the run, through the marketing gates', function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $list = app(MailingListRepository::class)->find('newsletter');

    app(SubscriptionService::class)->subscribe($list, 'seq@example.com', ['first_name' => 'Seq']);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'welcome-1',
        name: 'Welcome 1',
        subject: 'Welcome',
        listHandle: 'newsletter',
        content: '<p>Hi {{ first_name }}.</p>',
    ));

    $result = app('automations')->actions()->instance('marketing.send_email')->execute(
        AutomationContext::make(['subscriber' => ['email' => 'seq@example.com']]),
        ['campaign' => 'welcome-1'],
    );

    Mail::assertSent(CampaignMail::class, 1);

    expect($result->isSuccess())->toBeTrue()
        ->and($result->output['sent'])->toBeTrue()
        ->and($result->output['email'])->toBe('seq@example.com');
});

it('ends the flow rather than skipping one mail when consent is missing', function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'welcome-1',
        name: 'Welcome 1',
        subject: 'Welcome',
        listHandle: 'newsletter',
        content: '<p>Hi.</p>',
    ));

    $result = app('automations')->actions()->instance('marketing.send_email')->execute(
        AutomationContext::make(['subscriber' => ['email' => 'stranger@example.com']]),
        ['campaign' => 'welcome-1'],
    );

    Mail::assertNothingSent();

    // Stopped, not skipped-and-continue. Every later step of a marketing
    // sequence is more marketing mail.
    expect($result->isStopped())->toBeTrue()
        ->and($result->output['reason'])->toContain('not on list');
});

it('pauses the run instead of dropping a mail the frequency cap holds back', function (): void {
    Mail::fake();

    config()->set('marketing.frequency_cap.enabled', true);
    config()->set('marketing.frequency_cap.max', 1);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $list = app(MailingListRepository::class)->find('newsletter');
    app(SubscriptionService::class)->subscribe($list, 'capped@example.com', []);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'welcome-2',
        name: 'Welcome 2',
        subject: 'Second',
        listHandle: 'newsletter',
        content: '<p>Again.</p>',
    ));

    MailLogEntry::query()->create([
        'brand_id' => app('brand-context')->currentId(),
        'email_normalized' => 'capped@example.com',
        'mail_class' => 'marketing',
        'reference' => 'earlier',
        'sent_at' => now(),
    ]);

    $context = AutomationContext::make(['subscriber' => ['email' => 'capped@example.com']]);

    $result = app('automations')->actions()->instance('marketing.send_email')
        ->execute($context, ['campaign' => 'welcome-2']);

    Mail::assertNothingSent();

    // `waiting` is what the runner turns into a scheduled resume — the node is
    // asked again later, which is what a cap deserves and a hard no does not.
    expect($result->isWaiting())->toBeTrue()
        ->and($result->waitUntil['seconds'])->toBe(1440 * 60)
        ->and($result->output['reason'])->toBe('frequency_cap');

    // And the run is re-executed at this node rather than skipped past it.
    expect(SendMarketingEmailAction::reexecuteOnResume())->toBeTrue();
});

it('runs a two-mail sequence as an ordinary node chain', function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $list = app(MailingListRepository::class)->find('newsletter');

    foreach (['welcome-1' => 'Welcome', 'welcome-2' => 'Two days later'] as $handle => $subject) {
        app(CampaignRepository::class)->save(new Campaign(
            handle: $handle,
            name: $subject,
            subject: $subject,
            listHandle: 'newsletter',
            content: '<p>'.$subject.'</p>',
        ));
    }

    $automation = Automation::create(['name' => 'Welcome series', 'handle' => 'welcome_series', 'enabled' => true]);

    $automation->nodes()->create([
        'node_key' => 'trigger', 'type' => 'marketing.subscribed',
        'position_x' => 0, 'position_y' => 0, 'config' => ['list' => 'newsletter'],
    ]);
    $automation->nodes()->create([
        'node_key' => 'mail_1', 'type' => 'marketing.send_email',
        'position_x' => 250, 'position_y' => 0, 'config' => ['campaign' => 'welcome-1'],
    ]);
    $automation->nodes()->create([
        'node_key' => 'wait', 'type' => 'delay',
        'position_x' => 500, 'position_y' => 0, 'config' => ['amount' => 2, 'unit' => 'days'],
    ]);
    $automation->nodes()->create([
        'node_key' => 'mail_2', 'type' => 'marketing.send_email',
        'position_x' => 750, 'position_y' => 0, 'config' => ['campaign' => 'welcome-2'],
    ]);

    foreach ([['trigger', 'mail_1'], ['mail_1', 'wait'], ['wait', 'mail_2']] as [$from, $to]) {
        $automation->edges()->create(['from_node_key' => $from, 'to_node_key' => $to, 'from_output' => 'default']);
    }

    app(SubscriptionService::class)->subscribe($list, 'series@example.com', ['first_name' => 'Series']);

    // The first mail went out; the run is parked at the delay, which is exactly
    // what a sequence is. No sequence object was needed to get here.
    Mail::assertSent(CampaignMail::class, 1);

    $run = AutomationRun::query()->where('automation_id', $automation->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AutomationRun::STATUS_WAITING);
});
