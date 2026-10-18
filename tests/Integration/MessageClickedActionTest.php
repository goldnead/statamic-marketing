<?php

use Goldnead\Marketing\Integrations\Automations\Actions\MessageClickedAction;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Services\TrackingService;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * The click check: did anybody follow a tracked link in one delivery.
 *
 * Clicks are written the way the tracking controller writes them
 * (TrackingService::recordClick), so what the node reads is what production
 * stores, not a hand-made row.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    $this->travelTo('2026-10-13 10:00:00');

    $this->message = function (string $email = 'maria@example.com'): Message {
        // A template send: no campaign, no subscription, a template handle.
        return Message::create([
            'template_handle' => 'vokale-v1',
            'email' => $email,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    };

    $this->click = function (Message $message, string $url, string $after = '+0 seconds'): void {
        $this->travelTo(now()->modify($after));
        app(TrackingService::class)->recordClick($message->uuid, $url);
    };

    $this->check = fn (array $config, bool $testMode = false): ActionResult => (new MessageClickedAction)
        ->execute(AutomationContext::make([], $testMode), $config);
});

it('is registered as a built-in node', function (): void {
    $automations = app('automations');

    expect(MessageClickedAction::handle())->toBe('marketing.message_clicked')
        ->and($automations->actions()->instance('marketing.message_clicked'))->toBeInstanceOf(MessageClickedAction::class)
        ->and($automations->isBuiltIn('marketing.message_clicked'))->toBeTrue();
});

it('says no for a message nobody clicked in', function (): void {
    $message = ($this->message)();

    $result = ($this->check)(['message_uuid' => $message->uuid]);

    expect($result->status)->toBe('success')
        ->and($result->output['clicked'])->toBeFalse()
        ->and($result->output['clicks'])->toBe(0)
        ->and($result->output['url'])->toBeNull();
});

it('says yes after a click and names the link and the time', function (): void {
    $message = ($this->message)();
    ($this->click)($message, 'https://example.com/kurs?utm_content=v1-angebot', '+2 hours');

    $result = ($this->check)(['message_uuid' => $message->uuid]);

    expect($result->output['clicked'])->toBeTrue()
        ->and($result->output['clicks'])->toBe(1)
        ->and($result->output['url'])->toBe('https://example.com/kurs?utm_content=v1-angebot')
        ->and($result->output['clicked_at'])->toBe('2026-10-13T12:00:00+00:00');
});

it('only counts links that contain the given text', function (): void {
    $message = ($this->message)();
    ($this->click)($message, 'https://example.com/stimmanalyse');

    $other = ($this->check)(['message_uuid' => $message->uuid, 'url_contains' => 'utm_content=v1-angebot']);

    expect($other->output['clicked'])->toBeFalse();

    ($this->click)($message, 'https://example.com/kurs?utm_content=v1-angebot', '+1 hour');

    $hit = ($this->check)(['message_uuid' => $message->uuid, 'url_contains' => 'utm_content=v1-angebot']);

    expect($hit->output['clicked'])->toBeTrue()
        ->and($hit->output['clicks'])->toBe(1);
});

it('ignores clicks right after delivery when asked to, and counts later ones', function (): void {
    $message = ($this->message)();
    // A scanner: three seconds after the mail left.
    ($this->click)($message, 'https://example.com/kurs', '+3 seconds');

    $config = ['message_uuid' => $message->uuid, 'ignore_within_seconds' => 300];

    expect(($this->check)($config)->output['clicked'])->toBeFalse()
        // Without the window the same click counts.
        ->and(($this->check)(['message_uuid' => $message->uuid])->output['clicked'])->toBeTrue();

    // A person, an hour later.
    ($this->click)($message, 'https://example.com/kurs', '+1 hour');

    $result = ($this->check)($config);

    expect($result->output['clicked'])->toBeTrue()
        ->and($result->output['clicks'])->toBe(1);
});

it('does not take clicks from another message', function (): void {
    $mine = ($this->message)('maria@example.com');
    $theirs = ($this->message)('jonas@example.com');
    ($this->click)($theirs, 'https://example.com/kurs');

    expect(($this->check)(['message_uuid' => $mine->uuid])->output['clicked'])->toBeFalse();
});

it('does not read opens as clicks', function (): void {
    $message = ($this->message)();
    MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN]);

    expect(($this->check)(['message_uuid' => $message->uuid])->output['clicked'])->toBeFalse();
});

it('fails for an unknown message instead of answering no', function (): void {
    $result = ($this->check)(['message_uuid' => 'no-such-uuid']);

    expect($result->status)->toBe('failed');
});

it('fails when the message reference did not resolve', function (): void {
    $result = ($this->check)(['message_uuid' => '']);

    expect($result->status)->toBe('failed')
        ->and($result->output['missing_data_reference'])->toBe('message_uuid');
});

it('passes a test run without a message', function (): void {
    $result = ($this->check)([], testMode: true);

    expect($result->status)->toBe('success')
        ->and($result->output['clicked'])->toBeFalse();
});
