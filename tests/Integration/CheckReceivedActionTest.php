<?php

use Goldnead\Marketing\Integrations\Automations\Actions\CheckReceivedAction;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The check node: has this address already been sent a marketing mail.
 *
 * Messages are written as the send path writes them (status sent, sent_at set),
 * so the node reads what production stores.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    // 10:00 UTC is 12:00 in Berlin.
    $this->travelTo('2026-10-13 10:00:00');

    $this->mail = function (string $email, string $sentAt, array $attributes = []): Message {
        // A message always belongs to the subscription it was sent against.
        $subscription = Subscription::firstOrCreate(
            ['list_handle' => 'newsletter', 'email' => $email],
            ['status' => Subscription::STATUS_SUBSCRIBED],
        );

        return Message::create(array_merge([
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => Message::STATUS_SENT,
            'sent_at' => $sentAt,
            'campaign_handle' => 'brief',
        ], $attributes));
    };

    $this->check = function (array $config, string $email = 'maria@example.com', bool $testMode = false): ActionResult {
        $context = AutomationContext::make(['subscriber' => ['email' => $email]], $testMode);

        return (new CheckReceivedAction)->execute($context, $config);
    };
});

it('is registered as a built-in node', function (): void {
    $automations = app('automations');

    expect(CheckReceivedAction::handle())->toBe('marketing.check_received')
        ->and($automations->actions()->instance('marketing.check_received'))->toBeInstanceOf(CheckReceivedAction::class)
        ->and($automations->isBuiltIn('marketing.check_received'))->toBeTrue();
});

it('says no for an address that never received a mail', function (): void {
    $result = ($this->check)([]);

    expect($result->status)->toBe('success')
        ->and($result->output['received'])->toBeFalse()
        ->and($result->output['count'])->toBe(0)
        ->and($result->output['last_at'])->toBeNull();
});

it('counts the mails that left and names the last one', function (): void {
    ($this->mail)('maria@example.com', '2026-10-10 08:00:00');
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['status' => Message::STATUS_BOUNCED]);

    $result = ($this->check)([]);

    expect($result->output['received'])->toBeTrue()
        ->and($result->output['count'])->toBe(2)
        ->and($result->output['last_at'])->toBe('2026-10-12T08:00:00+00:00');
});

it('does not count rows that never left', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['status' => Message::STATUS_PENDING, 'sent_at' => null]);
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['status' => Message::STATUS_FAILED]);
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['status' => Message::STATUS_SKIPPED]);
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['status' => Message::STATUS_CAPPED]);

    expect(($this->check)([])->output['received'])->toBeFalse();
});

it('does not count mails sent to somebody else', function (): void {
    ($this->mail)('jonas@example.com', '2026-10-12 08:00:00');

    expect(($this->check)([])->output['received'])->toBeFalse();
});

it('compares the address without regard to case', function (): void {
    ($this->mail)('Maria@Example.COM', '2026-10-12 08:00:00');

    expect(($this->check)([], 'maria@example.com')->output['received'])->toBeTrue()
        ->and(($this->check)([], '  MARIA@example.com ')->output['received'])->toBeTrue();
});

it('narrows to a template, a comma list of templates, and a campaign', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['campaign_handle' => null, 'template_handle' => 'willkommen']);

    expect(($this->check)(['template' => 'willkommen'])->output['received'])->toBeTrue()
        ->and(($this->check)(['template' => 'anderes'])->output['received'])->toBeFalse()
        ->and(($this->check)(['template' => 'anderes, willkommen'])->output['received'])->toBeTrue()
        ->and(($this->check)(['campaign' => 'brief'])->output['received'])->toBeFalse();

    ($this->mail)('maria@example.com', '2026-10-12 09:00:00', ['campaign_handle' => 'brief']);

    expect(($this->check)(['campaign' => 'brief'])->output['received'])->toBeTrue()
        ->and(($this->check)(['campaign' => 'brief, anderes'])->output['count'])->toBe(1);
});

it('counts a mail that matches either the template or the campaign', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00', ['campaign_handle' => 'brief', 'template_handle' => null]);

    $result = ($this->check)(['template' => 'willkommen', 'campaign' => 'brief']);

    expect($result->output['received'])->toBeTrue()
        ->and($result->output['count'])->toBe(1);
});

it('keeps only the mails of the last N days with within_days', function (): void {
    ($this->mail)('maria@example.com', '2026-10-01 08:00:00');

    expect(($this->check)(['within_days' => 3])->output['received'])->toBeFalse()
        ->and(($this->check)(['within_days' => 30])->output['received'])->toBeTrue();

    ($this->mail)('maria@example.com', '2026-10-12 08:00:00');

    expect(($this->check)(['within_days' => 3])->output)->toMatchArray(['received' => true, 'count' => 1]);
});

it('refuses a within_days that is not a whole number of days', function (): void {
    expect(($this->check)(['within_days' => 0])->status)->toBe('failed')
        ->and(($this->check)(['within_days' => 1.5])->status)->toBe('failed')
        ->and(($this->check)(['within_days' => '2.5'])->status)->toBe('failed');
});

it('counts a mail that is on its way out, once its sent_at is stamped', function (): void {
    ($this->mail)('maria@example.com', '2026-10-13 09:00:00', ['status' => Message::STATUS_SENDING]);

    expect(($this->check)([])->output)->toMatchArray(['received' => true, 'count' => 1]);
});

it('finds the mails through the subscription, whatever the case of the address', function (): void {
    ($this->mail)('Maria@Example.com', '2026-10-12 08:00:00');

    expect(($this->check)([], 'maria@example.com')->output['received'])->toBeTrue()
        ->and(($this->check)([], 'Maria@Example.com')->output['count'])->toBe(1);
});

it('accepts an address with umlauts', function (): void {
    ($this->mail)('jürgen@example.com', '2026-10-12 08:00:00');

    $result = ($this->check)([], 'Jürgen@example.com');

    expect($result->status)->toBe('success')
        ->and($result->output['received'])->toBeTrue();
});

it('names no address in a stop reason', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00');

    $forbid = ($this->check)(['mode' => CheckReceivedAction::MODE_FORBID]);
    $require = ($this->check)(['mode' => CheckReceivedAction::MODE_REQUIRE], 'jonas@example.com');

    expect($forbid->status)->toBe('stopped')
        ->and($forbid->output['reason'])->not->toContain('maria@example.com')
        ->and($require->status)->toBe('stopped')
        ->and($require->output['reason'])->not->toContain('jonas@example.com');
});

it('offers the modes as a list of value and label', function (): void {
    $field = collect(CheckReceivedAction::schema())->firstWhere('handle', 'mode');

    expect($field['options'])->toBe([
        ['value' => 'report', 'label' => 'any (just report, never stop)'],
        ['value' => 'require', 'label' => 'the address has received such a mail'],
        ['value' => 'forbid', 'label' => 'the address has not received such a mail'],
    ]);
});

it('keeps only the mails of today in the day gate timezone with today', function (): void {
    // Berlin is UTC+2 in October. 23:30 UTC on the 12th is already the 13th in Berlin.
    ($this->mail)('maria@example.com', '2026-10-12 23:30:00');

    expect(($this->check)(['today' => true])->output['received'])->toBeTrue();

    ($this->mail)('jonas@example.com', '2026-10-12 21:00:00');

    expect(($this->check)(['today' => true], 'jonas@example.com')->output['received'])->toBeFalse();
});

it('applies within_days and today together, the stricter bound winning', function (): void {
    // In Berlin today starts at 2026-10-12 22:00 UTC.
    ($this->mail)('maria@example.com', '2026-10-12 23:00:00');

    expect(($this->check)(['today' => true, 'within_days' => 30])->output['received'])->toBeTrue()
        ->and(($this->check)(['today' => true, 'within_days' => 0])->status)->toBe('failed');

    $this->travelTo('2026-10-14 09:00:00');

    expect(($this->check)(['today' => true, 'within_days' => 30])->output['received'])->toBeFalse();
});

it('stops the run when the address has not received a mail, with require', function (): void {
    $result = ($this->check)(['mode' => CheckReceivedAction::MODE_REQUIRE]);

    expect($result->status)->toBe('stopped');
});

it('continues with require once a matching mail exists', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00');

    expect(($this->check)(['mode' => CheckReceivedAction::MODE_REQUIRE])->status)->toBe('success');
});

it('stops the run when the address has received a mail, with forbid', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00');

    expect(($this->check)(['mode' => CheckReceivedAction::MODE_FORBID])->status)->toBe('stopped')
        ->and(($this->check)(['mode' => CheckReceivedAction::MODE_FORBID], 'jonas@example.com')->status)->toBe('success');
});

it('never stops in report mode, whatever the answer', function (): void {
    ($this->mail)('maria@example.com', '2026-10-12 08:00:00');

    expect(($this->check)(['mode' => CheckReceivedAction::MODE_REPORT])->status)->toBe('success')
        ->and(($this->check)(['mode' => CheckReceivedAction::MODE_REPORT], 'jonas@example.com')->status)->toBe('success');
});

it('reads the configured address before the one the run is about', function (): void {
    ($this->mail)('jonas@example.com', '2026-10-12 08:00:00');

    $result = ($this->check)(['email' => 'Jonas@example.com'], 'maria@example.com');

    expect($result->output['received'])->toBeTrue();
});

it('fails for a broken address instead of answering no', function (): void {
    expect(($this->check)([], 'kein-echte-adresse')->status)->toBe('failed');
});

it('fails when the reading itself breaks instead of answering no', function (): void {
    // Schema::drop inside a transaction is rolled back on sqlite only. On MySQL DDL
    // commits, and the table would be gone for the rest of the suite.
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Dropping a table inside a transaction is only safe on sqlite.');
    }

    ($this->mail)('maria@example.com', '2026-10-12 08:00:00');

    // The table is gone for the length of this test; sqlite rolls the drop back.
    DB::beginTransaction();

    try {
        Schema::drop('marketing_messages');

        $result = ($this->check)(['mode' => CheckReceivedAction::MODE_FORBID]);
    } finally {
        DB::rollBack();
    }

    expect($result->status)->toBe('failed')
        ->and(Message::query()->count())->toBe(1);
});

it('fails when the address does not resolve outside a test run', function (): void {
    $result = (new CheckReceivedAction)->execute(AutomationContext::make([], false), []);

    expect($result->status)->toBe('failed')
        ->and($result->output['missing_data_reference'])->toBe('email');
});

it('passes a test run without an address', function (): void {
    $result = (new CheckReceivedAction)->execute(AutomationContext::make([], true), ['mode' => CheckReceivedAction::MODE_REQUIRE]);

    expect($result->status)->toBe('success')
        ->and($result->output['received'])->toBeFalse();
});
