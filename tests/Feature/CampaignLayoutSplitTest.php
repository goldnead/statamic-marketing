<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\EmailTemplate;
use Statamic\Facades\User;

/**
 * Two different things stopped sharing one select.
 *
 * The campaign editor offered "Template", and the list held both the envelopes a
 * campaign is sent in and the finished mails that have their own subject and
 * text. Choosing the second made it the campaign's *layout* — and since a
 * finished mail has no `{{ content }}` hole, the campaign's own words were
 * dropped in silence: written, sent, and absent from every inbox.
 *
 * Adrian found it by opening the select and asking what the FamilyStack mails
 * were doing in there.
 */
beforeEach(function (): void {
    $user = User::make()->email('split@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'newsletter',
        name: 'Newsletter',
        html: '<div>{{ content }}</div>',
    ));

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'nur-rahmen',
        name: 'Nur ein Rahmen',
        html: '<div>Kein Loch</div>',
    ));

    $this->props = fn () => json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('marketing.campaigns.create'))
            ->assertOk()
            ->getContent(),
        true,
    )['props'];
});

it('offers the layouts as their own list', function (): void {
    $handles = collect(($this->props)()['layouts'])->pluck('value');

    expect($handles)->toContain('newsletter')
        ->and($handles)->toContain('nur-rahmen');
});

it('offers the finished mails as a separate list', function (): void {
    // Empty here because email-templates is not installed in this test bed —
    // which is itself the point: the second list is optional, and its absence
    // must not take the first one with it.
    expect(($this->props)())->toHaveKey('readyMades');
});

it('no longer offers one mixed list', function (): void {
    // The prop the old screen read. Its absence is what stops the two kinds of
    // thing from being one question again.
    expect(($this->props)())->not->toHaveKey('templates');
});

it('says which layout would swallow the campaign text', function (): void {
    $layouts = collect(($this->props)()['layouts'])->keyBy('value');

    expect($layouts['newsletter']['has_content_hole'])->toBeTrue()
        ->and($layouts['nur-rahmen']['has_content_hole'])->toBeFalse();
});

it('reads the hole with any spacing inside the braces', function (): void {
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'eng',
        name: 'Eng geschrieben',
        html: '<div>{{content}}</div>',
    ));

    $layouts = collect(($this->props)()['layouts'])->keyBy('value');

    expect($layouts['eng']['has_content_hole'])->toBeTrue();
});

it('does not mistake the word content for the hole', function (): void {
    // Same false positive as the layout editor guards against: a warning that
    // fires on a correct layout is how warnings stop being read.
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'prosa',
        name: 'Prosa',
        html: '<p>Here is the content of the mail.</p>',
    ));

    $layouts = collect(($this->props)()['layouts'])->keyBy('value');

    expect($layouts['prosa']['has_content_hole'])->toBeFalse();
});
