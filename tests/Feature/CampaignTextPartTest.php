<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\PreferenceLink;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * Der `text/plain`-Teil einer Kampagne — die Fassung, die niemand ansieht und
 * die trotzdem für sich allein stehen muss.
 *
 * Zwei Fehler steckten bis 2.3.0 darin, beide erst sichtbar, wenn man die
 * ausgehende Nachricht aufmacht statt das HTML:
 *
 * 1. Der einzige Abmeldelink darin war der Fußzeilen-Link, und der zeigt auf
 *    das Preference Center, sobald das Geschwister-Addon installiert ist — eine
 *    Seite mit Kästchen, auf der ein Klick niemanden abmeldet. Für eine
 *    Werbemail an eine deutsche Empfängerliste ist das der falsche Link.
 * 2. Die Zeile wurde beim Rendern gebaut, also in der Sprache der Anwendung.
 *    Eine deutsche Kampagne eines Hosts mit `APP_LOCALE=en` trug „Unsubscribe".
 */
beforeEach(function (): void {
    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subscription = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');

    $this->message = Message::create([
        'campaign_handle' => 'welcome',
        'subscription_id' => $this->subscription->id,
        'email' => $this->subscription->email,
        'status' => Message::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $this->campaign = new Campaign(
        handle: 'welcome',
        name: 'Welcome',
        subject: 'Hallo',
        listHandle: 'newsletter',
        content: '<p>Erste Ausgabe.</p>',
    );
});

function textteil(?string $locale = null): string
{
    $rendered = app(CampaignRenderer::class)->render(
        test()->campaign,
        test()->list,
        test()->subscription,
        test()->message,
    );

    $mailable = new CampaignMail(test()->campaign, $rendered);

    if ($locale) {
        $mailable->locale($locale);
    }

    Mail::to('jane@example.com')->send($mailable);

    /** @var Email $message */
    $message = collect(Mail::mailer('array')->getSymfonyTransport()->messages())
        ->last()
        ->getOriginalMessage();

    return (string) $message->getTextBody();
}

it('carries the one-click unsubscribe url, not the preference page', function (): void {
    marketingFakePreferenceCenterClass();
    marketingFakePreferenceCenterRoute();

    $einKlick = app(PreferenceLink::class)->oneClick($this->subscription->token);
    $zentrum = app(PreferenceLink::class)->manage($this->subscription->token);

    // Wächter: solange die beiden gleich sind, prüft der Test nichts.
    expect($zentrum)->not->toBe($einKlick);

    $text = textteil();

    expect($text)->toContain($einKlick)
        ->and($text)->not->toContain('/!/preference-center/t/');
});

it('writes the unsubscribe line in the language of the recipient', function (): void {
    app()->setLocale('en');

    expect(textteil('de'))->toContain('Abmelden: ');
    expect(textteil('en'))->toContain('Unsubscribe: ');
});

it('leaves the unsubscribe line out where there is nothing to unsubscribe from', function (): void {
    // Eine Vorschau ohne Empfängerin: der Renderer liefert `#`, und eine Zeile
    // „Abmelden: #" wäre schlimmer als keine.
    $rendered = app(CampaignRenderer::class)->render($this->campaign, $this->list);

    Mail::to('jane@example.com')->send(new CampaignMail($this->campaign, $rendered));

    $text = (string) collect(Mail::mailer('array')->getSymfonyTransport()->messages())
        ->last()
        ->getOriginalMessage()
        ->getTextBody();

    expect($text)->toContain('Erste Ausgabe.')
        ->and($text)->not->toContain('Abmelden')
        ->and($text)->not->toContain('Unsubscribe');
});

it('keeps the rendered text free of the unsubscribe line itself', function (): void {
    // Die Zeile gehört der Ansicht. Stünde sie zusätzlich im gerenderten Text,
    // stünde sie zweimal in der Mail — und einmal davon in der falschen
    // Sprache, was der Anlass für den Umbau war.
    $rendered = app(CampaignRenderer::class)->render(
        $this->campaign,
        $this->list,
        $this->subscription,
        $this->message,
    );

    expect($rendered->text)->toBe('Erste Ausgabe.');
});

/**
 * In einer Textdatei gibt es nichts zu entkommen.
 *
 * Blade escapt `{{ }}` grundsätzlich, auch wenn das Ergebnis nie in HTML
 * landet: aus „Musik & Chor" wird „Musik &amp; Chor". Der Renderer löst
 * Entitäten eine Zeile vorher ausdrücklich auf — die Ansicht hat das wieder
 * zurückgedreht.
 */
it('does not html-escape the text part', function (): void {
    $this->campaign->content = '<p>Musik &amp; Chor, "in Anführungszeichen".</p>';

    $text = textteil();

    expect($text)->toContain('Musik & Chor')
        ->and($text)->toContain('"in Anführungszeichen"')
        ->and($text)->not->toContain('&amp;')
        ->and($text)->not->toContain('&quot;');
});

/**
 * Ein Link, dessen Ziel nur im HTML steht, führt im Textteil nirgendwohin.
 *
 * Auf staging gemessen am 18.09.2026: der ganze Textteil einer Kampagne trug
 * genau eine URL, nämlich die zum Abmelden. Der Satz „Mehr dazu steht auf
 * adriangoldner.com" war im HTML ein Link und im Text eine Sackgasse.
 */
it('writes each link target next to its text', function (): void {
    $this->campaign->content =
        '<p>Mehr dazu steht <a href="https://adriangoldner.com/wissen">im Wissensbereich</a>.</p>';

    expect(textteil())->toContain('im Wissensbereich (https://adriangoldner.com/wissen)');
});

it('does not repeat a target that is already the link text', function (): void {
    $this->campaign->content =
        '<p><a href="https://adriangoldner.com">https://adriangoldner.com</a>, '
        .'<a href="mailto:info@adriangoldner.com">info@adriangoldner.com</a>, '
        .'<a href="#oben">nach oben</a>.</p>';

    $text = textteil();

    expect($text)->toContain('https://adriangoldner.com,')
        ->and($text)->toContain('info@adriangoldner.com,')
        ->and($text)->toContain('nach oben.')
        // Keine Klammer-Dopplung und kein Anker, der im Text nirgends hinführt.
        ->and($text)->not->toContain('(https://adriangoldner.com)')
        ->and($text)->not->toContain('(mailto:')
        ->and($text)->not->toContain('(#oben)');
});

/**
 * Die Anbieterkennzeichnung (§ 5 DDG) steht unter dem HTML-Teil. Wer die Mail
 * als reinen Text liest, sah bis 2.23.1 keine.
 */
it('carries the postal line in the text part, too', function (): void {
    config()->set('marketing.footer.postal_line', 'Adrian Goldner, Keplerstraße 25, 60318 Frankfurt am Main');

    expect(textteil())->toContain('Keplerstraße 25, 60318 Frankfurt am Main');
});

it('leaves the postal line out when none is configured', function (): void {
    config()->set('marketing.footer.postal_line', null);

    // Ein Addon kann die Anschrift seines Betreibers nicht erfinden, und eine
    // erfundene wäre schlimmer als keine.
    expect(trim(textteil()))->not->toEndWith(',');
});
