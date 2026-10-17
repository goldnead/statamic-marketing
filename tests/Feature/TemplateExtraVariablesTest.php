<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

/**
 * Extra variables on a template send: `marketing.send_email` in template mode
 * takes name → value pairs, and the template and the subject can print them.
 *
 * The rule that matters is the second one: the pairs add to the built-in
 * variables and never replace one. A mail author who types `email` or
 * `unsubscribe_url` into the node must not be able to point the unsubscribe
 * link somewhere else, by accident or because a value came out of a run.
 */
beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->jane = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'freebie',
        name: 'Freebie',
        html: '<html><body><p>Hier ist dein {{ freebie_title }}, {{ first_name }}.</p>'
            .'<a href="{{ unsubscribe_url }}">Abmelden</a></body></html>',
    ));

    $this->send = function (array $variables, string $subject = 'Dein {{ freebie_title }}') {
        return app(SingleSend::class)->sendTemplate(
            'freebie',
            $subject,
            $this->list,
            $this->jane->fresh(),
            variables: $variables,
        );
    };
});

it('prints the variables in the template and in the subject', function (): void {
    ($this->send)(['freebie_title' => 'Atem-Guide']);

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return str_contains($mail->rendered->html, 'Hier ist dein Atem-Guide, Jane.')
            && $mail->rendered->subject === 'Dein Atem-Guide';
    });
});

it('sends exactly as before without variables', function (): void {
    ($this->send)([], 'Hallo');

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return str_contains($mail->rendered->html, 'Hier ist dein , Jane.')
            && $mail->rendered->subject === 'Hallo';
    });
});

it('never overrides a built-in variable', function (): void {
    ($this->send)([
        'first_name' => 'Mallory',
        'email' => 'mallory@example.com',
        'unsubscribe_url' => 'https://evil.example/out',
        'sequence_unsubscribe_url' => 'https://evil.example/out',
        'subject' => 'Evil',
        'freebie_title' => 'Atem-Guide',
    ]);

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return str_contains($mail->rendered->html, 'Jane.')
            && ! str_contains($mail->rendered->html, 'Mallory')
            && ! str_contains($mail->rendered->html, 'evil.example')
            && $mail->rendered->subject === 'Dein Atem-Guide';
    });
});

it('ignores names Antlers cannot print and values that are not text', function (): void {
    ($this->send)([
        'freebie_title' => 'Atem-Guide',
        'with space' => 'x',
        '1st' => 'x',
        'nested' => ['a' => 'b'],
        0 => 'x',
    ]);

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return str_contains($mail->rendered->html, 'Atem-Guide') && ! str_contains($mail->rendered->html, 'Array');
    });
});

it('escapes a value in the HTML and leaves the subject as plain text', function (): void {
    // Antlers prints a variable as it finds it, so without the escape in the
    // renderer a `<script>` in a product title would land in the mail body.
    ($this->send)(['freebie_title' => '<script>alert(1)</script> Tom & Jerry']);

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return ! str_contains($mail->rendered->html, '<script>')
            && str_contains($mail->rendered->html, '&lt;script&gt;alert(1)&lt;/script&gt; Tom &amp; Jerry')
            // A subject is not HTML: entities there would be shown literally.
            && $mail->rendered->subject === 'Dein <script>alert(1)</script> Tom & Jerry';
    });
});

it('does not parse a value as a template of its own', function (): void {
    // A value that came out of a run is data. If it were parsed it could read
    // the rest of the render context.
    ($this->send)(['freebie_title' => '{{ unsubscribe_url }}'], 'Hallo');

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return str_contains($mail->rendered->html, 'Hier ist dein {{ unsubscribe_url }}, Jane.');
    });
});
