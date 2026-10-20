<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\Actions\CheckTagAction;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\TagLinks;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * The tag check: does the subscription of this address on a list carry a tag.
 *
 * Tags are put on the person by the tag link itself (TagLinks::apply), so the
 * node reads what a real click wrote.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->subscribe = function (string $email, string $status = Subscription::STATUS_SUBSCRIBED): Subscription {
        return Subscription::create([
            'list_handle' => 'newsletter',
            'email' => $email,
            'status' => $status,
        ]);
    };

    $this->tag = function (Subscription $subscription, string $tag): void {
        app(TagLinks::class)->apply($subscription, [$tag]);
    };

    $this->check = function (array $config, string $email = 'maria@example.com', bool $testMode = false): ActionResult {
        $context = AutomationContext::make(['subscriber' => ['email' => $email]], $testMode);

        return (new CheckTagAction)->execute($context, array_merge(['list' => 'newsletter', 'tag' => 'wants-freebie'], $config));
    };
});

it('is registered as a built-in node', function (): void {
    $automations = app('automations');

    expect(CheckTagAction::handle())->toBe('marketing.check_tag')
        ->and($automations->actions()->instance('marketing.check_tag'))->toBeInstanceOf(CheckTagAction::class)
        ->and($automations->isBuiltIn('marketing.check_tag'))->toBeTrue();
});

it('answers no and no without a subscription on the list, and does not fail', function (): void {
    $result = ($this->check)([]);

    expect($result->status)->toBe('success')
        ->and($result->output['has_tag'])->toBeFalse()
        ->and($result->output['subscribed'])->toBeFalse()
        ->and($result->output['tags'])->toBe([]);
});

it('reports a subscribed person without the tag', function (): void {
    ($this->subscribe)('maria@example.com');

    $result = ($this->check)([]);

    expect($result->output['has_tag'])->toBeFalse()
        ->and($result->output['subscribed'])->toBeTrue();
});

it('finds the tag a tag link put on the person', function (): void {
    $subscription = ($this->subscribe)('maria@example.com');
    ($this->tag)($subscription, 'interessiert-an-stimmanalyse');
    ($this->tag)($subscription, 'wants-freebie');

    $result = ($this->check)([]);

    expect($result->output['has_tag'])->toBeTrue()
        ->and($result->output['tags'])->toContain('wants-freebie', 'interessiert-an-stimmanalyse');

    expect(($this->check)(['tag' => 'nicht-gesetzt'])->output['has_tag'])->toBeFalse();
});

it('keeps the answer for an address that unsubscribed, and says it is no longer subscribed', function (): void {
    $subscription = ($this->subscribe)('maria@example.com');
    ($this->tag)($subscription, 'wants-freebie');
    $subscription->update(['status' => Subscription::STATUS_UNSUBSCRIBED]);

    $result = ($this->check)([]);

    expect($result->output['has_tag'])->toBeTrue()
        ->and($result->output['subscribed'])->toBeFalse();
});

it('matches the address without regard to case', function (): void {
    $subscription = ($this->subscribe)('Maria@Example.com');
    ($this->tag)($subscription, 'wants-freebie');

    expect(($this->check)([], 'maria@example.com')->output['has_tag'])->toBeTrue();
});

it('stops the run when the tag is missing, with require', function (): void {
    ($this->subscribe)('maria@example.com');

    expect(($this->check)(['mode' => CheckTagAction::MODE_REQUIRE])->status)->toBe('stopped');

    $subscription = Subscription::query()->where('email', 'maria@example.com')->first();
    ($this->tag)($subscription, 'wants-freebie');

    expect(($this->check)(['mode' => CheckTagAction::MODE_REQUIRE])->status)->toBe('success');
});

it('stops the run when the tag is there, with forbid', function (): void {
    $subscription = ($this->subscribe)('maria@example.com');
    ($this->tag)($subscription, 'wants-freebie');

    expect(($this->check)(['mode' => CheckTagAction::MODE_FORBID])->status)->toBe('stopped')
        ->and(($this->check)(['mode' => CheckTagAction::MODE_FORBID], 'jonas@example.com')->status)->toBe('success');
});

it('never stops in report mode', function (): void {
    expect(($this->check)(['mode' => CheckTagAction::MODE_REPORT])->status)->toBe('success');
});

it('fails for a list that does not exist instead of answering no', function (): void {
    expect(($this->check)(['list' => 'gibt-es-nicht'])->status)->toBe('failed');
});

it('fails for a missing list or tag, a static configuration error', function (): void {
    expect(($this->check)(['list' => ''])->status)->toBe('failed')
        ->and(($this->check)(['tag' => '   '])->status)->toBe('failed');
});

it('fails for a broken address', function (): void {
    expect(($this->check)([], 'kein-echte-adresse')->status)->toBe('failed');
});

it('fails when the address does not resolve outside a test run', function (): void {
    $result = (new CheckTagAction)->execute(AutomationContext::make([], false), ['list' => 'newsletter', 'tag' => 'x']);

    expect($result->status)->toBe('failed')
        ->and($result->output['missing_data_reference'])->toBe('email');
});

it('passes a test run without an address', function (): void {
    $result = (new CheckTagAction)->execute(AutomationContext::make([], true), [
        'list' => 'newsletter',
        'tag' => 'wants-freebie',
        'mode' => CheckTagAction::MODE_REQUIRE,
    ]);

    expect($result->status)->toBe('success')
        ->and($result->output['has_tag'])->toBeFalse();
});
