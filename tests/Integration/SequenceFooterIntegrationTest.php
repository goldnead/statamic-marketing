<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;

/**
 * Der Serien-Ausstieg im Fuss einer Mail — über beide Addons hinweg.
 *
 * Marketing rendert die Mail und kennt die Route des Ausstiegs nicht; die
 * Automationen kennen sie und rendern keine Mails. Der Link entsteht erst
 * dort, wo beide installiert sind, und genau das prueft dieser Test.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subscription = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');

    // Ein Rahmen ohne eigenen Fuss — der Fall, in dem das Netz greift.
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'nackt',
        name: 'Ohne Fuss',
        html: '<html><body><main>{{ content }}</main></body></html>',
    ));

    $this->flow = Automation::create([
        'name' => 'Willkommensstrecke',
        'handle' => 'willkommensstrecke',
        'enabled' => true,
    ]);
});

function renderFromSequence(?string $sequenceUuid)
{
    return app(CampaignRenderer::class)->render(
        new Campaign(
            handle: 'welcome',
            name: 'Welcome',
            subject: 'Hallo',
            content: '<p>Hallo!</p>',
            templateHandle: 'nackt',
        ),
        test()->list,
        test()->subscription,
        null,
        $sequenceUuid,
    );
}

it('setzt den Serien-Ausstieg in den Fuss', function (): void {
    $rendered = renderFromSequence($this->flow->uuid);

    expect($rendered->html)->toContain('/serie/');
    expect($rendered->html)->toContain($this->subscription->token);
    expect($rendered->html)->toContain($this->flow->uuid);
});

it('zeigt den Serien-Ausstieg VOR der vollstaendigen Abmeldung', function (): void {
    /*
     * Die Reihenfolge ist die Aussage, nicht Kosmetik.
     *
     * Wer eine Willkommensstrecke loswerden will, will selten den Newsletter
     * los. Stuende die vollstaendige Abmeldung vorn, klickt sie jemand, weil
     * sie die erste ist — und ist dann ganz weg. Der kleinere Schritt gehoert
     * nach vorn.
     */
    $rendered = renderFromSequence($this->flow->uuid);

    $serie = strpos($rendered->html, '/serie/');
    $abmelden = strpos($rendered->html, $rendered->unsubscribeUrl);

    expect($serie)->not->toBeFalse();
    expect($abmelden)->not->toBeFalse();
    expect($serie)->toBeLessThan($abmelden);
});

it('setzt keinen Serien-Link, wenn die Mail aus keiner Serie kommt', function (): void {
    // Eine Kampagne ist keine Serie. Ein Link mit dem Versprechen "diese Serie
    // abbestellen", der den Newsletter beendet, waere eine Falle.
    $rendered = renderFromSequence(null);

    expect($rendered->html)->not->toContain('/serie/');
    expect($rendered->html)->toContain($rendered->unsubscribeUrl);
});
