<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\TagLinks;
use Goldnead\Marketing\Tags\MarketingTagLink;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Statamic\Facades\Antlers;

/**
 * Tag links: `{{ marketing_tag_link:key }}` in a mail, a page that sets nothing
 * when it is opened, and a POST that sets the tags.
 *
 * Everything here is about one question: what may happen when something other
 * than the reader opens the link. A mail gateway fetches every URL in a message,
 * and a click counter wraps and forwards every `href`. Neither may tag anybody.
 */
beforeEach(function (): void {
    Mail::fake();

    config()->set('app.url', 'http://localhost');
    config()->set('marketing.tag_links', [
        'wants-freebie' => ['tags' => ['interest:freebie', 'source:mail'], 'redirect' => '/freebie/danke'],
        'abc-def' => ['tags' => ['abc'], 'redirect' => 'https://example.org/ziel?x=1'],
        'other-key' => ['tags' => ['other'], 'redirect' => '/anders'],
        'on-other-list' => ['tags' => ['x'], 'redirect' => '/x', 'list' => 'other'],
        'open-redirect' => ['tags' => ['x'], 'redirect' => '//evil.example/x'],
        'no-tags' => ['tags' => [], 'redirect' => '/x'],
        'thanks-only' => ['tags' => ['too-expensive'], 'thanks' => 'Danke dir, das hilft mir. <b>Und mehr</b>'],
        'thanks-and-redirect' => ['tags' => ['both'], 'thanks' => 'Danke.', 'redirect' => '/ignored'],
        'thanks-blank' => ['tags' => ['blank'], 'thanks' => '   '],
    ]);

    MarketingTagLink::register();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->jane = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);

    $this->tagsOf = fn (string $email = 'jane@example.com'): array => LeadHub::findByEmail($email)['tags'] ?? [];

    /** The link exactly as the tag prints it, for Jane on the newsletter list. */
    $this->link = fn (string $key = 'wants-freebie'): string => renderTagLink(
        '{{ marketing_tag_link:'.$key.' }}',
        ['email' => 'jane@example.com', 'list' => ['handle' => 'newsletter']],
    );
});

/** The template as a mail renders it: user content, so Statamic's allowlist applies. */
function renderTagLink(string $template, array $variables): string
{
    return (string) Antlers::parse($template, $variables);
}

/* ------------------------------------------------------------- the tag */

it('prints a signed URL that names the subscription and not the address', function (): void {
    $url = ($this->link)();

    expect($url)->toStartWith('http://localhost/!/marketing/tag/wants-freebie?')
        ->and($url)->toContain('s='.$this->jane->uuid)
        ->and($url)->toContain('signature=')
        ->and($url)->not->toContain('jane')
        ->and($url)->not->toContain('example.com')
        ->and($url)->not->toContain('%40');
});

it('resolves a key with a hyphen in an href of the rendered mail', function (): void {
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'tag-mail',
        name: 'Tag mail',
        html: '<html><body><a href="{{ marketing_tag_link:abc-def }}">Ja, gern</a></body></html>',
    ));

    app(SingleSend::class)->sendTemplate('tag-mail', 'Hallo', $this->list, $this->jane->fresh());

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        // The click counter wraps it; what it wraps is the signed tag URL.
        preg_match('/href="([^"]+)"/', $mail->rendered->html, $m);
        $wrapped = html_entity_decode($m[1]);
        parse_str((string) parse_url($wrapped, PHP_URL_QUERY), $query);

        return str_contains($wrapped, '/!/marketing/c/')
            && str_starts_with($query['url'], 'http://localhost/!/marketing/tag/abc-def?')
            && str_contains($query['url'], 's='.test()->jane->uuid)
            && ! str_contains($mail->rendered->html, 'marketing_tag_link');
    });
});

it('resolves in the content of a campaign, which Statamic treats as user content', function (): void {
    $campaign = new Campaign(
        handle: 'bard',
        name: 'Bard',
        subject: 'Hallo',
        listHandle: 'newsletter',
        content: '<p>Magst du das? <a href="{{ marketing_tag_link:abc-def }}">Ja</a></p>',
    );

    $html = app(CampaignRenderer::class)->render($campaign, $this->list, $this->jane->fresh())->html;

    expect($html)->toContain('href="http://localhost/!/marketing/tag/abc-def?')
        ->and($html)->toContain('s='.$this->jane->uuid)
        ->and($html)->not->toContain('jane@example.com');
});

it('prints # for an unknown key, a broken definition and a render without a subscription', function (): void {
    $jane = ['email' => 'jane@example.com', 'list' => ['handle' => 'newsletter']];

    expect(renderTagLink('{{ marketing_tag_link:nope }}', $jane))->toBe('#')
        ->and(renderTagLink('{{ marketing_tag_link:no-tags }}', $jane))->toBe('#')
        ->and(renderTagLink('{{ marketing_tag_link:open-redirect }}', $jane))->toBe('#')
        // The Control Panel preview: a sample address nobody subscribed.
        ->and(renderTagLink('{{ marketing_tag_link:wants-freebie }}', ['email' => 'preview@example.com', 'list' => ['handle' => 'newsletter']]))->toBe('#')
        // No address in the context at all.
        ->and(renderTagLink('{{ marketing_tag_link:wants-freebie }}', ['list' => ['handle' => 'newsletter']]))->toBe('#')
        // No list to look the subscription up on.
        ->and(renderTagLink('{{ marketing_tag_link:wants-freebie }}', ['email' => 'jane@example.com']))->toBe('#');
});

it('takes the list from the definition when it names one', function (): void {
    app(MailingListRepository::class)->save(new MailingList(handle: 'other', name: 'Other', doubleOptIn: false));
    app(SubscriptionService::class)->subscribe(app(MailingListRepository::class)->find('other'), 'jane@example.com');
    $other = Subscription::query()->forList('other')->first();

    $url = renderTagLink('{{ marketing_tag_link:on-other-list }}', ['email' => 'jane@example.com', 'list' => ['handle' => 'newsletter']]);

    expect($url)->toContain('s='.$other->uuid);
});

/* --------------------------------------------------------- the two routes */

it('sets nothing when the page is opened', function (): void {
    $before = ($this->tagsOf)();

    $this->get(($this->link)())
        ->assertOk()
        ->assertSee('One moment, I am saving your choice.', false)
        ->assertSee('Continue')
        ->assertSee('name="robots" content="noindex"', false)
        ->assertSee('<form method="POST"', false)
        ->assertSee('document.getElementById', false);

    expect(($this->tagsOf)())->toBe($before)
        ->and(($this->tagsOf)())->not->toContain('interest:freebie');
});

it('speaks German where the application does', function (): void {
    app()->setLocale('de');

    $this->get(($this->link)())
        ->assertOk()
        ->assertSee('Einen Moment, ich speichere deine Auswahl.', false)
        ->assertSee('Weiter');
});

it('posts to the address it was opened on, signature included', function (): void {
    $url = ($this->link)();

    $this->get($url)->assertSee('action="'.e($url).'"', false);
});

it('sets the tags on the POST and moves the reader on to the configured target', function (): void {
    $this->post(($this->link)())->assertRedirect('http://localhost/freebie/danke');

    expect(($this->tagsOf)())->toContain('interest:freebie', 'source:mail')
        ->and($this->jane->fresh()->contact_uuid)->not->toBeNull();
});

it('sends the reader to an absolute target as it is', function (): void {
    $this->post(($this->link)('abc-def'))->assertRedirect('https://example.org/ziel?x=1');
});

it('changes nothing on a second POST', function (): void {
    $url = ($this->link)();

    $this->post($url)->assertRedirect();
    $after = ($this->tagsOf)();
    $this->post($url)->assertRedirect();

    expect(($this->tagsOf)())->toBe($after)
        ->and(array_count_values($after)['interest:freebie'])->toBe(1);
});

it('creates the contact when the subscription has none', function (): void {
    $lonely = Subscription::query()->create([
        'list_handle' => 'newsletter',
        'email' => 'lonely@example.com',
        'first_name' => 'Lea',
        'status' => Subscription::STATUS_SUBSCRIBED,
        'subscribed_at' => now(),
    ]);

    expect(LeadHub::findByEmail('lonely@example.com'))->toBeNull();

    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'wants-freebie', 's' => $lonely->uuid]);
    $this->post($url)->assertRedirect('http://localhost/freebie/danke');

    $contact = LeadHub::findByEmail('lonely@example.com');

    expect($contact)->not->toBeNull()
        ->and($contact['tags'])->toContain('interest:freebie')
        ->and($lonely->fresh()->contact_uuid)->toBe($contact['uuid']);
});

it('keeps the tags on the subscription when LeadHub is not there', function (): void {
    app()->bind(TagLinks::class, fn () => new class extends TagLinks
    {
        protected function leadhubAvailable(): bool
        {
            return false;
        }
    });

    $url = ($this->link)();

    $this->post($url)->assertRedirect();
    $this->post($url)->assertRedirect();

    expect($this->jane->fresh()->meta['tags'])->toBe(['interest:freebie', 'source:mail'])
        ->and(($this->tagsOf)())->not->toContain('interest:freebie');
});

/* ----------------------------------------------------------- the refusals */

it('refuses a forged or missing signature on both verbs', function (): void {
    $url = ($this->link)();
    $forged = preg_replace('/signature=[^&]+/', 'signature=deadbeef', $url);
    $unsigned = 'http://localhost/!/marketing/tag/wants-freebie?s='.$this->jane->uuid;

    foreach ([$forged, $unsigned] as $bad) {
        $this->get($bad)->assertForbidden();
        $this->post($bad)->assertForbidden();
    }

    expect(($this->tagsOf)())->not->toContain('interest:freebie');
});

it('refuses another key under an old signature', function (): void {
    $url = ($this->link)();
    $swapped = str_replace('/tag/wants-freebie', '/tag/other-key', $url);

    $this->get($swapped)->assertForbidden();
    $this->post($swapped)->assertForbidden();
});

it('refuses another subscription under an old signature', function (): void {
    $other = app(SubscriptionService::class)->subscribe($this->list, 'mallory@example.com');
    $swapped = str_replace($this->jane->uuid, $other->uuid, ($this->link)());

    $this->post($swapped)->assertForbidden();

    expect(LeadHub::findByEmail('mallory@example.com')['tags'] ?? [])->not->toContain('interest:freebie');
});

it('answers 404 for an unknown key, even when the URL is signed', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'nope', 's' => $this->jane->uuid]);

    $this->get($url)->assertNotFound();
    $this->post($url)->assertNotFound();
});

it('answers 404 for an unknown subscription, even when the URL is signed', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'wants-freebie', 's' => '00000000-0000-4000-8000-000000000000']);

    $this->get($url)->assertNotFound();
    $this->post($url)->assertNotFound();

    $none = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'wants-freebie']);
    $this->get($none)->assertNotFound();
});

it('answers 404 when the subscription is not on the list the definition names', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'on-other-list', 's' => $this->jane->uuid]);

    $this->post($url)->assertNotFound();
});

it('never redirects to a target taken from the request', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', [
        'marketingTagKey' => 'wants-freebie',
        's' => $this->jane->uuid,
        'redirect' => 'https://evil.example',
        'url' => 'https://evil.example',
    ]);

    $this->post($url)->assertRedirect('http://localhost/freebie/danke');
});

it('answers 404 for a definition whose redirect would leave the site by accident', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'open-redirect', 's' => $this->jane->uuid]);

    $this->post($url)->assertNotFound();
});

/* ------------------------------------------------- the click counter's way */

it('survives the click counter: tracker, then page, then POST', function (): void {
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'tag-mail',
        name: 'Tag mail',
        html: '<html><body><a href="{{ marketing_tag_link:wants-freebie }}">Ja, gern</a></body></html>',
    ));

    app(SingleSend::class)->sendTemplate('tag-mail', 'Hallo', $this->list, $this->jane->fresh());

    $tracked = null;
    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) use (&$tracked): bool {
        preg_match('/href="([^"]+)"/', $mail->rendered->html, $m);
        $tracked = html_entity_decode($m[1]);

        return true;
    });

    // 1 — the counter forwards the reader to the tag URL, untouched.
    $hop = $this->get($tracked);
    $hop->assertRedirect();
    $tagUrl = $hop->headers->get('Location');

    expect($tagUrl)->toStartWith('http://localhost/!/marketing/tag/wants-freebie?');

    // 2 — the page validates the signature the mail was rendered with and sets nothing.
    $this->get($tagUrl)->assertOk();
    expect(($this->tagsOf)())->not->toContain('interest:freebie');

    // 3 — the button, or the script, does.
    $this->post($tagUrl)->assertRedirect('http://localhost/freebie/danke');
    expect(($this->tagsOf)())->toContain('interest:freebie');
});

it('tolerates the parameter a mail platform adds when it forwards the reader', function (): void {
    $url = ($this->link)();
    $withSe = str_replace('?', '?_se='.rtrim(strtr(base64_encode('jane@example.com'), '+/', '-_'), '=').'&', $url);

    $this->get($withSe)->assertOk();
    $this->post($withSe)->assertRedirect('http://localhost/freebie/danke');
});

it('answers the POST with a thank-you page instead of a redirect when the definition has a thanks text', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'thanks-only', 's' => $this->jane->uuid]);

    // The GET is the confirmation page and still sets nothing.
    $this->get($url)->assertOk();
    expect(LeadHub::findByEmail('jane@example.com')['tags'] ?? [])->not->toContain('too-expensive');

    $response = $this->post($url);

    $response->assertOk()
        ->assertSee('Danke dir, das hilft mir.')
        // Plain text: markup in the text is escaped, not rendered.
        ->assertDontSee('<b>Und mehr</b>', false)
        ->assertSee('&lt;b&gt;Und mehr&lt;/b&gt;', false);

    expect(LeadHub::findByEmail('jane@example.com')['tags'])->toContain('too-expensive');
});

it('prefers the thanks text over a redirect', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'thanks-and-redirect', 's' => $this->jane->uuid]);

    $this->post($url)->assertOk()->assertSee('Danke.');
});

it('treats a blank thanks text and no redirect as no definition', function (): void {
    $url = URL::signedRoute('marketing.tag-link.show', ['marketingTagKey' => 'thanks-blank', 's' => $this->jane->uuid]);

    $this->post($url)->assertNotFound();
});
