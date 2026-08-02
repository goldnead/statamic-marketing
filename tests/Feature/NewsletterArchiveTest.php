<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Support\ArchiveDocument;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The public newsletter web archive.
 *
 * Two of these are named in the specification and are the reason the others
 * exist around them: an unreleased campaign answers 404 rather than 403, and
 * the archive page carries no tracking of any kind.
 */
function marketingArchiveSuperuser()
{
    $user = User::make()->email('editor@example.com')->makeSuper();
    $user->save();

    return $user;
}

/** Can look at marketing, cannot manage campaigns. */
function marketingArchiveViewer()
{
    $user = User::make()->email('viewer@example.com');
    $user->assignRole(Role::make('marketing-viewer')->permissions(['view marketing'])->save());
    $user->save();

    return $user;
}

beforeEach(function (): void {
    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->saveCampaign = function (array $overrides = []): Campaign {
        $campaign = new Campaign(
            handle: $overrides['handle'] ?? 'july_issue',
            name: $overrides['name'] ?? 'July issue',
            subject: $overrides['subject'] ?? 'What is coming up',
            preheader: $overrides['preheader'] ?? 'A short summary',
            listHandle: 'newsletter',
            content: $overrides['content'] ?? '<p>Hallo {{ first_name }}, read our <a href="https://example.com/news">news</a>.</p>',
            status: $overrides['status'] ?? Campaign::STATUS_SENT,
            sentAt: $overrides['sentAt'] ?? CarbonImmutable::parse('2026-07-01 09:00:00'),
            inArchive: $overrides['inArchive'] ?? true,
        );

        app(CampaignRepository::class)->save($campaign);

        return $campaign;
    };
});

it('answers 404, not 403, for a campaign that has not been released', function (): void {
    ($this->saveCampaign)(['inArchive' => false]);

    $response = $this->get('/newsletter/july_issue');

    // The distinction is the whole point. 403 is the accurate status and the
    // wrong one to send: it confirms that a campaign with this handle exists,
    // which turns a guessable URL into a way to enumerate unpublished issues.
    expect($response->status())->toBe(404)
        ->and($response->status())->not->toBe(403);
});

it('answers 404 for a campaign handle that does not exist at all', function (): void {
    // The same answer as the test above, from the same URL shape — which is
    // what makes "not released" and "not there" indistinguishable from outside.
    $this->get('/newsletter/never_existed')->assertNotFound();
});

it('does not publish a released campaign before it has actually been sent', function (): void {
    // Flagged, but still a draft. Publishing here would put the issue on the
    // open web before its own subscribers have it.
    ($this->saveCampaign)(['status' => Campaign::STATUS_DRAFT, 'sentAt' => null]);

    $this->get('/newsletter/july_issue')->assertNotFound();
});

it('serves a released campaign', function (): void {
    ($this->saveCampaign)();

    $this->get('/newsletter/july_issue')
        ->assertOk()
        ->assertSee('read our', false);
});

it('puts no tracking of any kind on the archive page', function (): void {
    ($this->saveCampaign)();

    $html = $this->get('/newsletter/july_issue')->getContent();

    // An open in the archive is not an open of the e-mail, and a click counted
    // with no recipient behind it would be added to the campaign's rate as if
    // somebody who received the mail had clicked. Both numbers would go up and
    // mean less, so neither mechanism may appear here.
    expect($html)->not->toContain('/!/marketing/o/')
        ->and($html)->not->toContain('/!/marketing/c/')
        ->and($html)->not->toContain('signature=');

    // And the destination links are the campaign's own, unrewritten.
    expect($html)->toContain('href="https://example.com/news"');
});

it('resolves personalisation placeholders to something neutral', function (): void {
    ($this->saveCampaign)(['content' => '<p>Hallo {{ first_name }}, {{ email }}|{{ name }}|</p>']);

    $html = $this->get('/newsletter/july_issue')->getContent();

    // Raw braces are the embarrassing failure; `Hallo ,` is the quieter one.
    expect($html)->not->toContain('{{ first_name }}')
        ->and($html)->not->toContain('{{ name }}')
        ->and($html)->toContain('Hallo there,')
        // No address is invented for a copy that went to nobody.
        ->and($html)->toContain('|there|');
});

it('honours a configured neutral name', function (): void {
    config()->set('marketing.archive.neutral_name', 'Leser:in');

    ($this->saveCampaign)(['content' => '<p>Hallo {{ first_name }}!</p>']);

    expect($this->get('/newsletter/july_issue')->getContent())->toContain('Hallo Leser:in!');
});

it('writes the SEO head into the campaign document', function (): void {
    ($this->saveCampaign)();

    $html = $this->get('/newsletter/july_issue')->getContent();

    expect($html)->toContain('<title>What is coming up</title>')
        ->and($html)->toContain('<meta name="description" content="A short summary">')
        ->and($html)->toContain('<link rel="canonical" href="'.url('/newsletter/july_issue').'">')
        ->and($html)->toContain('<meta property="og:title" content="What is coming up">')
        ->and($html)->toContain('<meta property="og:type" content="article">')
        ->and($html)->toContain('<meta property="article:published_time"');

    // Being findable is the feature, so nothing here may ask not to be indexed.
    expect($html)->not->toContain('noindex');
});

it('keeps the campaign HTML inert without hiding it from a crawler', function (): void {
    ($this->saveCampaign)();

    $csp = $this->get('/newsletter/july_issue')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'none'")
        // No script source is handed back, so a <script> that reaches a
        // template cannot run against this site's origin.
        ->and($csp)->not->toContain('script-src')
        // …but not `sandbox`, which the CP preview uses: an opaque origin is
        // not something a page meant to be read and shared can be.
        ->and($csp)->not->toContain('sandbox');
});

it('lists only released campaigns on the index, newest first', function (): void {
    ($this->saveCampaign)(['handle' => 'june_issue', 'subject' => 'June', 'sentAt' => CarbonImmutable::parse('2026-06-01 09:00')]);
    ($this->saveCampaign)(['handle' => 'july_issue', 'subject' => 'July', 'sentAt' => CarbonImmutable::parse('2026-07-01 09:00')]);
    ($this->saveCampaign)(['handle' => 'secret_issue', 'subject' => 'Secret', 'inArchive' => false]);

    $html = $this->get('/newsletter')->assertOk()->getContent();

    expect($html)->toContain('July')
        ->and($html)->toContain('June')
        ->and($html)->not->toContain('Secret')
        ->and(strpos($html, 'July'))->toBeLessThan(strpos($html, 'June'));
});

it('serves an RSS feed over the released campaigns', function (): void {
    ($this->saveCampaign)();
    ($this->saveCampaign)(['handle' => 'secret_issue', 'subject' => 'Secret', 'inArchive' => false]);

    $response = $this->get('/newsletter/feed.xml')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/rss+xml');

    $xml = $response->getContent();

    expect($xml)->toContain('<title>What is coming up</title>')
        ->and($xml)->toContain(url('/newsletter/july_issue'))
        ->and($xml)->not->toContain('Secret');

    // It has to actually parse, which is the part an escaping mistake breaks.
    expect(simplexml_load_string($xml))->not->toBeFalse();
});

it('keeps feed.xml out of the campaign route', function (): void {
    // Declared first and constrained out of `{marketingCampaign}`. Without
    // both, the feed URL resolves as a campaign lookup and 404s.
    $this->get('/newsletter/feed.xml')->assertOk();
});

it('serves nothing at all when the archive is switched off', function (): void {
    config()->set('marketing.archive.enabled', false);

    ($this->saveCampaign)();

    $this->get('/newsletter')->assertNotFound();
    $this->get('/newsletter/feed.xml')->assertNotFound();
    $this->get('/newsletter/july_issue')->assertNotFound();
});

it('defaults a campaign to being out of the archive', function (): void {
    // The safety property of the whole feature: applying the migration and
    // updating the package publishes nothing.
    $campaign = new Campaign(handle: 'plain', name: 'Plain');

    expect($campaign->inArchive)->toBeFalse()
        ->and($campaign->isArchived())->toBeFalse();

    expect(Campaign::fromArray(['handle' => 'legacy', 'name' => 'Legacy', 'status' => 'sent'])->inArchive)
        ->toBeFalse();
});

it('releases and withdraws a campaign from the control panel', function (): void {
    ($this->saveCampaign)(['inArchive' => false]);

    $this->actingAs(marketingArchiveSuperuser());

    $this->patch(cp_route('marketing.campaigns.archive', 'july_issue'), ['archive' => true])
        ->assertRedirect();

    expect(app(CampaignRepository::class)->find('july_issue')->inArchive)->toBeTrue();
    $this->get('/newsletter/july_issue')->assertOk();

    $this->patch(cp_route('marketing.campaigns.archive', 'july_issue'), ['archive' => false])
        ->assertRedirect();

    expect(app(CampaignRepository::class)->find('july_issue')->inArchive)->toBeFalse();

    // Withdrawal takes effect on the next request — nothing caches the list.
    $this->get('/newsletter/july_issue')->assertNotFound();
});

it('releases a campaign that can no longer be edited', function (): void {
    // The reason this is its own endpoint. `update()` refuses a sent campaign,
    // and a sent campaign is exactly when the question gets asked.
    $campaign = ($this->saveCampaign)(['inArchive' => false]);

    expect($campaign->isEditable())->toBeFalse();

    $this->actingAs(marketingArchiveSuperuser());

    $this->patch(cp_route('marketing.campaigns.archive', 'july_issue'), ['archive' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('july_issue')->inArchive)->toBeTrue();
});

it('refuses to release a campaign without the campaign permission', function (): void {
    ($this->saveCampaign)(['inArchive' => false]);

    $this->actingAs(marketingArchiveViewer());

    $this->patch(cp_route('marketing.campaigns.archive', 'july_issue'), ['archive' => true])
        ->assertForbidden();

    expect(app(CampaignRepository::class)->find('july_issue')->inArchive)->toBeFalse();
});

it('wraps a template that has no head of its own', function (): void {
    // A layout that is a bare fragment has nowhere to write the head into, so
    // the addon supplies the document instead of dropping the SEO tags.
    $fragment = new ArchiveDocument('<div>content</div>', ['title' => 'Issue']);

    expect($fragment->needsWrapper())->toBeTrue()
        ->and($fragment->head())->toContain('<title>Issue</title>');

    $document = new ArchiveDocument('<html><head><title>Layout</title></head><body>x</body></html>', ['title' => 'Issue']);

    expect($document->needsWrapper())->toBeFalse();

    // One title, and it is the campaign's — two titles in one document is
    // invalid and which one wins is the parser's choice, not ours.
    $rendered = $document->render();

    expect(substr_count($rendered, '<title>'))->toBe(1)
        ->and($rendered)->toContain('<title>Issue</title>');
});

it('escapes a subject that would otherwise break out of a meta attribute', function (): void {
    $document = new ArchiveDocument(
        '<html><head></head><body>x</body></html>',
        ['title' => 'He said "hello" & left', 'description' => '"><script>alert(1)</script>'],
    );

    $rendered = $document->render();

    expect($rendered)->not->toContain('<script>')
        ->and($rendered)->toContain('&quot;');
});
