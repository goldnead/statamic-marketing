<?php

use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Sending\SendMode;

/**
 * Which mail `marketing.send_email` was configured to send, and the three ways
 * that question can have no answer.
 *
 * These are configuration errors rather than send failures, and the difference
 * is not cosmetic: a configuration error has to surface in a *test run*, where
 * there is no recipient, no context and nothing to send. An editor finds out
 * that a node is broken by pressing Test — not three days later when the first
 * person reaches that step of the flow and quietly gets nothing.
 *
 * The rule lives on marketing's side of the automations boundary and so does
 * its coverage: it is marketing that decides what a marketing mail needs before
 * it may go out, and holding that rule must not depend on which optional
 * orchestrator happens to be installed.
 */
it('reads a campaign node as campaign mode', function (): void {
    $mode = SendMode::fromConfig(['campaign' => 'welcome-1']);

    expect($mode->isValid())->toBeTrue()
        ->and($mode->isCampaign())->toBeTrue()
        ->and($mode->isTemplate())->toBeFalse()
        ->and($mode->campaign)->toBe('welcome-1')
        // Still optional here, exactly as before: campaign mode falls back to
        // the campaign's own list.
        ->and($mode->list)->toBe('');
});

it('reads a template node as template mode', function (): void {
    $mode = SendMode::fromConfig([
        'template' => 'welcome-sequenz-1-willkommen',
        'subject' => '{{ subscriber.first_name }}, schön, dass du dabei bist',
        'list' => 'newsletter',
    ]);

    expect($mode->isValid())->toBeTrue()
        ->and($mode->isTemplate())->toBeTrue()
        ->and($mode->template)->toBe('welcome-sequenz-1-willkommen')
        ->and($mode->list)->toBe('newsletter')
        ->and($mode->mailClass)->toBe(MailClass::Marketing);
});

it('refuses a node that names both a campaign and a template', function (): void {
    $mode = SendMode::fromConfig([
        'campaign' => 'welcome-1',
        'template' => 'welcome-sequenz-1-willkommen',
        'subject' => 'Hi',
        'list' => 'newsletter',
    ]);

    // Two answers to "what is this mail". The node cannot pick, and picking one
    // silently is how somebody's carefully written campaign gets replaced by a
    // layout without anybody being told.
    expect($mode->isValid())->toBeFalse()
        ->and($mode->isCampaign())->toBeFalse()
        ->and($mode->isTemplate())->toBeFalse()
        ->and($mode->error)->toContain('welcome-1')
        ->and($mode->error)->toContain('welcome-sequenz-1-willkommen')
        ->and($mode->error)->toContain('not both');
});

it('refuses a node that names neither a campaign nor a template', function (): void {
    $mode = SendMode::fromConfig(['to' => '{{ subscriber.email }}']);

    expect($mode->isValid())->toBeFalse()
        ->and($mode->error)->toContain('campaign')
        ->and($mode->error)->toContain('template');
});

it('refuses a template node with no mailing list', function (): void {
    $mode = SendMode::fromConfig([
        'template' => 'welcome-sequenz-1-willkommen',
        'subject' => 'Willkommen',
    ]);

    // The one that would otherwise send. A campaign carries its own list, so
    // the field was allowed to be empty; a template carries none, and an empty
    // field there means a marketing mail with nothing behind it to show that
    // the recipient ever agreed to receive it.
    expect($mode->isValid())->toBeFalse()
        ->and($mode->isTemplate())->toBeFalse()
        ->and($mode->error)->toContain('list')
        ->and($mode->error)->toContain('consent');
});

it('refuses a template node with no subject', function (): void {
    $mode = SendMode::fromConfig([
        'template' => 'welcome-sequenz-1-willkommen',
        'list' => 'newsletter',
    ]);

    expect($mode->isValid())->toBeFalse()
        ->and($mode->error)->toContain('subject');
});

it('treats whitespace as absent in every field', function (): void {
    // A select cleared in the CP posts an empty string, and a text field a user
    // has emptied often posts a space. Both have to read as "not set", or the
    // both-are-set error fires on a node that names exactly one mail.
    $mode = SendMode::fromConfig([
        'campaign' => '   ',
        'template' => ' welcome-sequenz-1-willkommen ',
        'subject' => ' Willkommen ',
        'list' => ' newsletter ',
    ]);

    expect($mode->isValid())->toBeTrue()
        ->and($mode->isTemplate())->toBeTrue()
        ->and($mode->template)->toBe('welcome-sequenz-1-willkommen')
        ->and($mode->subject)->toBe('Willkommen')
        ->and($mode->list)->toBe('newsletter');
});

it('reads the classification, and reads anything unrecognised as marketing', function (): void {
    $base = [
        'template' => 'welcome-sequenz-1-willkommen',
        'subject' => 'Willkommen',
        'list' => 'newsletter',
    ];

    expect(SendMode::fromConfig($base + ['mail_class' => 'transactional'])->mailClass)
        ->toBe(MailClass::Transactional)
        ->and(SendMode::fromConfig($base + ['mail_class' => 'reminder'])->mailClass)
        ->toBe(MailClass::Reminder);

    // The conservative direction. A typo, a value from a newer release or a
    // field nobody filled in must cost a delay, never an exemption.
    foreach ([null, '', '   ', 'markting', 'urgent', 42] as $nonsense) {
        expect(SendMode::fromConfig($base + ['mail_class' => $nonsense])->mailClass)
            ->toBe(MailClass::Marketing, 'mail_class ['.var_export($nonsense, true).'] must read as marketing');
    }
});
