<?php

use Goldnead\EmailTemplates\Snapshots\Snapshots;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Services\VariantAssigner;
use Goldnead\Suppression\Contracts\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Was der Versand festhaelt, und vor allem: was er nicht festhaelt.
 *
 * Der Entwurf der Snapshot-Schicht steht und faellt mit einer Zusage — in der
 * Zeile steht die Vorlage mit ihren Platzhaltern, nie die gerenderte Mail eines
 * Empfaengers. Nur weil das gilt, braucht die Tabelle keine Frist und kein
 * Loeschkonzept. Die Schicht kann diese Zusage nicht erzwingen (ihre Wache
 * erkennt sicher nur signierte URLs und den Zaehlpixel), also wird sie hier
 * gepruefet.
 */

// Stand-in fuer das OPTIONALE email-templates-Addon (in diesem Repo nicht im vendor/).
require_once __DIR__.'/../Fixtures/SnapshotsStub.php';

beforeEach(function (): void {
    Mail::fake();
    Snapshots::reset();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'haus',
        name: 'Haus',
        html: '<!DOCTYPE html><html><body><h1>{{ subject }}</h1>{{ content }}'
            .'<a href="{{ unsubscribe_url }}">Abmelden</a></body></html>',
    ));

    $this->campaign = new Campaign(
        handle: 'september',
        name: 'September',
        subject: 'Hallo {{ first_name }}',
        listHandle: 'newsletter',
        templateHandle: 'haus',
        content: '<p>Schoen, dass du da bist, {{ first_name }}.</p>',
        status: Campaign::STATUS_SENDING,
    );

    app(CampaignRepository::class)->save($this->campaign);
});

function abonniere(string $email, string $vorname): void
{
    app(SubscriptionService::class)->subscribe(test()->list, $email, ['first_name' => $vorname]);
}

function starteVersand(): void
{
    (new StartCampaignJob('september'))->handle(
        app(CampaignRepository::class),
        app(ContactRepository::class),
        app(Gate::class),
        app(VariantAssigner::class),
    );
}

it('haelt einen Versand an drei Empfaenger als genau eine Zeile fest', function (): void {
    Queue::fake();

    abonniere('anna@example.com', 'Anna');
    abonniere('bert@example.com', 'Bert');
    abonniere('carla@example.com', 'Carla');

    starteVersand();

    // Drei Empfaengerzeilen, ein Schnappschuss. Genau das ist die Bauart: die
    // Empfaenger stehen schon in marketing_messages und werden nicht kopiert.
    expect(Message::forCampaign('september')->count())->toBe(3);
    expect(Snapshots::$recorded)->toHaveCount(1);

    $aufruf = Snapshots::$recorded[0];

    expect($aufruf['ownerType'])->toBe('marketing:campaign');
    expect($aufruf['ownerId'])->toBe('september');
});

it('schreibt keinen Empfaengertext in die Zeile', function (): void {
    Queue::fake();

    abonniere('anna@example.com', 'Anna');
    abonniere('bert@example.com', 'Bert');

    starteVersand();

    $vorlage = Snapshots::$recorded[0]['template'];
    $alles = $vorlage['subject'].$vorlage['body'];

    // Kein Name, keine Adresse, keine Kennung eines Empfaengers.
    foreach (['anna@example.com', 'bert@example.com', 'Anna', 'Bert'] as $spur) {
        expect($alles)->not->toContain($spur);
    }

    // Und keine der beiden Spuren, an denen die Schicht selbst gerenderten
    // Inhalt erkennt: eine signierte URL und der Zaehlpixel je Nachricht.
    expect($alles)->not->toMatch('/[?&]signature=[a-f0-9]{16,}/i');
    expect($alles)->not->toMatch('#/o/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.gif#i');

    // Die Platzhalter stehen dagegen noch da, sonst waere es keine Vorlage.
    expect($vorlage['subject'])->toContain('{{ first_name }}');
    expect($vorlage['body'])->toContain('{{ first_name }}');
    expect($vorlage['body'])->toContain('{{ unsubscribe_url }}');
});

it('setzt den Kampagnentext in die Vorlage ein, statt einen leeren Rahmen festzuhalten', function (): void {
    Queue::fake();

    abonniere('anna@example.com', 'Anna');

    starteVersand();

    $body = Snapshots::$recorded[0]['template']['body'];

    expect($body)->toContain('Schoen, dass du da bist');
    // Der Platzhalter ist verbraucht, nicht stehengeblieben.
    expect($body)->not->toContain('{{ content }}');
    // Und es ist ein vollstaendiges Dokument, das die Vorschau nicht ein
    // zweites Mal umschliessen muss.
    expect(strtolower(substr(trim($body), 0, 9)))->toBe('<!doctype');
});

it('nimmt die Anschriftenzeile mit, aber nicht den Selbstbedienungs-Fuss eines Empfaengers', function (): void {
    config()->set('marketing.footer.postal_line', "Adrian Goldner\nBeispielweg 1, 60311 Frankfurt");

    $body = app(CampaignRenderer::class)->templateAtSendTime($this->campaign);

    // Pflichtangabe nach § 5 DDG: stand in jeder Mail, gehoert also in den
    // Schnappschuss.
    expect($body)->toContain('Beispielweg 1');

    // Der gerenderte Fuss eines Empfaengers gehoert nicht hinein. Zum
    // Vergleich: die echte Mail traegt eine signierte Abmelde-Adresse.
    expect($body)->not->toMatch('/[?&]signature=[a-f0-9]{16,}/i');
});

it('haelt nichts fest, wenn die Kampagne gar nicht im Versand ist', function (): void {
    Queue::fake();

    $entwurf = app(CampaignRepository::class)->find('september');
    $entwurf->status = Campaign::STATUS_DRAFT;
    app(CampaignRepository::class)->save($entwurf);

    abonniere('anna@example.com', 'Anna');

    starteVersand();

    expect(Snapshots::$recorded)->toBeEmpty();
});
