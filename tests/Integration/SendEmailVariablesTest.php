<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\Actions\SendMarketingEmailAction;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Engine\TokenResolver;
use Goldnead\StatamicAutomations\Facades\Automations;
use Illuminate\Support\Facades\Mail;

/**
 * `marketing.send_email` in template mode takes `variables`: pairs whose values
 * resolve against the run like the subject does, and which the template and the
 * subject can print. The renderer's side of this is TemplateExtraVariablesTest;
 * what is checked here is the node: its field, and the whole way from a token in
 * a value to the word in the mail.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'freebie-mail',
        name: 'Freebie',
        html: '<html><body><p>Hallo {{ first_name }}, hier ist {{ freebie_title }}.</p></body></html>',
    ));

    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'vera@example.com',
        ['first_name' => 'Vera'],
    );

    $this->runNode = function (array $variables, ?array $data = null) {
        $context = AutomationContext::make($data ?? [
            'subscriber' => ['email' => 'vera@example.com'],
            'lookup' => ['product_title' => 'Atem-Guide'],
        ]);

        // What the engine does before an action sees its config.
        $config = app(TokenResolver::class)->resolve([
            'template' => 'freebie-mail',
            'subject' => 'Dein {{ lookup.product_title }}',
            'list' => 'newsletter',
            'variables' => $variables,
        ], $context);

        return app('automations')->actions()->instance('marketing.send_email')->execute($context, $config);
    };
});

it('declares variables as an optional, tokenable key_value field', function (): void {
    $field = collect(SendMarketingEmailAction::schema())->firstWhere('handle', 'variables');

    expect($field['type'])->toBe('key_value')
        ->and($field['tokenable'])->toBeTrue()
        ->and($field['required'])->toBeFalse();
});

it('prints a variable whose value is a token of the run', function (): void {
    $result = ($this->runNode)(['freebie_title' => '{{ lookup.product_title }}']);

    expect($result->isSuccess())->toBeTrue();

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => str_contains($mail->rendered->html, 'Hallo Vera, hier ist Atem-Guide.')
        && $mail->rendered->subject === 'Dein Atem-Guide');
});

it('accepts the pairs as a list of key and value, which is how the builder may store them', function (): void {
    ($this->runNode)([['key' => 'freebie_title', 'value' => '{{ lookup.product_title }}']]);

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => str_contains($mail->rendered->html, 'hier ist Atem-Guide.'));
});

it('lets a built-in variable win over a pair of the same name', function (): void {
    ($this->runNode)(['first_name' => 'Mallory', 'freebie_title' => 'x']);

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => str_contains($mail->rendered->html, 'Hallo Vera,')
        && ! str_contains($mail->rendered->html, 'Mallory'));
});

it('escapes a value that came out of the run', function (): void {
    ($this->runNode)(['freebie_title' => '{{ lookup.product_title }}'], [
        'subscriber' => ['email' => 'vera@example.com'],
        'lookup' => ['product_title' => '<script>alert(1)</script>'],
    ]);

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => ! str_contains($mail->rendered->html, '<script>')
        && str_contains($mail->rendered->html, '&lt;script&gt;'));
});

it('sends as before when the node has no variables', function (): void {
    ($this->runNode)([]);

    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => str_contains($mail->rendered->html, 'Hallo Vera, hier ist .'));
});

it('shows the pairs in a test run and sends nothing', function (): void {
    $context = AutomationContext::make([], true);

    $result = app('automations')->actions()->instance('marketing.send_email')->execute($context, [
        'template' => 'freebie-mail',
        'subject' => 'Hallo',
        'list' => 'newsletter',
        'variables' => ['freebie_title' => 'Atem-Guide'],
    ]);

    Mail::assertNothingSent();

    expect($result->output['preview']['variables'])->toBe(['freebie_title' => 'Atem-Guide']);
});
