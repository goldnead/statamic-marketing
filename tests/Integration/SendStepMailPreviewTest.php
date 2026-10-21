<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\Actions\SendMarketingEmailAction;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\StatamicAutomations\Facades\Automations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * The dry run of a mail series (the automations map) shows every `marketing.send_email` step as the
 * mail it would send. The step's own class says how: `mailPreview()` renders it for a person made of
 * a few fields. The shape under test is the real one: template mode with a template slug, a subject
 * and a list (`minikurs-cvt-tag-N` on adriangoldner.com), and campaign mode.
 *
 * Whatever it does, it does not send, does not write a message row, does not ask the frequency cap
 * and does not rewrite a link to a tracking address or add an open pixel.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'minikurs', name: 'Minikurs', doubleOptIn: false));

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'minikurs-cvt-tag-1',
        name: 'Tag 1',
        html: '<html><body><p>Hallo {{ first_name }}, {{ freebie_title }}.</p><a href="https://example.com/start">Start</a><a href="{{ unsubscribe_url }}">Abmelden</a></body></html>',
    ));

    $this->person = ['email' => 'vera@example.com', 'first_name' => 'Vera', 'last_name' => 'Test', 'name' => 'Vera Test'];
    $this->template = ['template' => 'minikurs-cvt-tag-1', 'subject' => '{{ first_name }}, dein erster Tag', 'list' => 'minikurs'];
});

it('renders a template mode step with the person in subject and body', function (): void {
    $mail = SendMarketingEmailAction::mailPreview($this->template, $this->person);

    expect($mail)->not->toBeNull()
        ->and($mail['subject'])->toBe('Vera, dein erster Tag')
        ->and($mail['html'])->toContain('Hallo Vera')
        ->and($mail['from'])->toBeNull();
});

it('prints the variables of the step as long as they are plain text', function (): void {
    $mail = SendMarketingEmailAction::mailPreview(
        $this->template + ['variables' => [['key' => 'freebie_title', 'value' => 'Atem-Guide'], ['key' => 'offen', 'value' => '{{ nodes.lookup.x }}']]],
        $this->person,
    );

    expect($mail['html'])->toContain('Atem-Guide.');
});

it('renders a campaign mode step with the subject and the content of the campaign', function (): void {
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'willkommen',
        name: 'Willkommen',
        subject: 'Willkommen, {{ first_name }}',
        listHandle: 'minikurs',
        templateHandle: 'minikurs-cvt-tag-1',
        content: '<p>Schoen, dass du da bist.</p>',
    ));

    $mail = SendMarketingEmailAction::mailPreview(['campaign' => 'willkommen'], $this->person);

    expect($mail['subject'])->toBe('Willkommen, Vera')
        ->and($mail['html'])->toContain('Hallo Vera');
});

it('has no preview for a step that names no existing mail', function (): void {
    expect(SendMarketingEmailAction::mailPreview(['template' => 'gibt-es-nicht', 'subject' => 'x', 'list' => 'minikurs'], $this->person))->toBeNull()
        ->and(SendMarketingEmailAction::mailPreview(['campaign' => 'gibt-es-nicht'], $this->person))->toBeNull()
        ->and(SendMarketingEmailAction::mailPreview([], $this->person))->toBeNull();
});

it('sends nothing, writes nothing, asks no cap and tracks no link', function (): void {
    $before = [
        'messages' => Message::query()->count(),
        'subscriptions' => Subscription::query()->count(),
        'cap' => DB::table('marketing_mail_log')->count(),
    ];

    $mail = SendMarketingEmailAction::mailPreview($this->template, $this->person);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    expect($mail['html'])->toContain('href="https://example.com/start"')
        ->and($mail['html'])->not->toContain('/t/')
        ->and($mail['html'])->not->toContain('<img src')
        ->and(Message::query()->count())->toBe($before['messages'])
        ->and(Subscription::query()->count())->toBe($before['subscriptions'])
        ->and(DB::table('marketing_mail_log')->count())->toBe($before['cap']);
});
