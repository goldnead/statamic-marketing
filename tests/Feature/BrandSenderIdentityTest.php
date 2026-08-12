<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Contracts\SenderIdentityResolver;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who a marketing mail goes out as.
 *
 * `Mail::fake()` is deliberately NOT used in this file. The fake records the
 * name of the mailer but never renders the message, and the From is decided
 * during the render — so the fake can prove the transport and not the sender,
 * which is exactly one half of the bug. Every brand here gets its own `array`
 * transport instead: the assertions then read the real MIME message out of the
 * transport that actually accepted it, which answers both questions with one
 * observation.
 *
 * The bug this file exists for: chorgesucht's double opt-in confirmation went
 * out through FamilyStack's Scaleway project, under FamilyStack's From, because
 * every send path read the global `marketing.sending.mailer` (12.08.2026).
 */
beforeEach(function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    // One array transport per brand plus one for the configured default, so
    // "which transport received it" is a question with a real answer.
    config()->set('mail.mailers.marke_a', ['transport' => 'array']);
    config()->set('mail.mailers.marke_b', ['transport' => 'array']);
    config()->set('mail.mailers.global', ['transport' => 'array']);
    config()->set('marketing.sending.mailer', 'global');
    config()->set('marketing.from.email', 'global@example.com');
    config()->set('marketing.from.name', 'Global');

    $this->brandA = Brand::create([
        'handle' => 'marke-a',
        'name' => 'Marke A',
        'settings' => ['mail' => [
            'from_address' => 'noreply@marke-a.test',
            'from_name' => 'Marke A Newsletter',
            'mailer' => 'marke_a',
            'locale' => 'de',
        ]],
    ]);

    $this->brandB = Brand::create([
        'handle' => 'marke-b',
        'name' => 'Marke B',
        'settings' => ['mail' => [
            'from_address' => 'noreply@marke-b.test',
            'mailer' => 'marke_b',
        ]],
    ]);

    $this->mails = fn (string $mailer) => Mail::mailer($mailer)->getSymfonyTransport()->messages();

    $this->list = function (Brand $brand, string $handle): MailingList {
        return BrandContext::runFor($brand, function () use ($handle) {
            app(MailingListRepository::class)->save(new MailingList(
                handle: $handle,
                name: ucfirst($handle),
                doubleOptIn: true,
            ));

            return app(MailingListRepository::class)->find($handle);
        });
    };
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(fn () => Subscription::query()->delete());
});

it('sends brand A\'s confirmation through brand A\'s mailer and never through brand B\'s', function (): void {
    $list = ($this->list)($this->brandA, 'liste-a');

    BrandContext::runFor($this->brandA, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'anna@example.com'));

    $a = ($this->mails)('marke_a');
    $b = ($this->mails)('marke_b');
    $global = ($this->mails)('global');

    expect($a)->toHaveCount(1)
        ->and($b)->toHaveCount(0)
        ->and($global)->toHaveCount(0);

    $message = $a[0]->getOriginalMessage();

    expect($message->getFrom()[0]->getAddress())->toBe('noreply@marke-a.test')
        ->and($message->getFrom()[0]->getName())->toBe('Marke A Newsletter')
        ->and($message->getTo()[0]->getAddress())->toBe('anna@example.com');
});

it('keeps two brands apart across two sends in the same process', function (): void {
    $listA = ($this->list)($this->brandA, 'liste-a');
    $listB = ($this->list)($this->brandB, 'liste-b');

    BrandContext::runFor($this->brandA, fn () => app(SubscriptionService::class)
        ->subscribe($listA, 'anna@example.com'));

    BrandContext::runFor($this->brandB, fn () => app(SubscriptionService::class)
        ->subscribe($listB, 'bert@example.com'));

    // The queue worker case: brand A's send must not have left its From
    // standing for the brand that sends next.
    expect(($this->mails)('marke_a'))->toHaveCount(1)
        ->and(($this->mails)('marke_b'))->toHaveCount(1);

    expect(($this->mails)('marke_a')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-a.test');

    expect(($this->mails)('marke_b')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-b.test');
});

it('writes the confirmation in the brand\'s language', function (): void {
    app()->setLocale('en');

    $listA = ($this->list)($this->brandA, 'liste-a');
    $listB = ($this->list)($this->brandB, 'liste-b');

    BrandContext::runFor($this->brandA, fn () => app(SubscriptionService::class)
        ->subscribe($listA, 'anna@example.com'));

    BrandContext::runFor($this->brandB, fn () => app(SubscriptionService::class)
        ->subscribe($listB, 'bert@example.com'));

    // Brand A says `locale: de` — German subject, even though the app is English.
    expect(($this->mails)('marke_a')[0]->getOriginalMessage()->getSubject())
        ->toStartWith('Bitte bestätige deine Anmeldung');

    // Brand B says nothing about language, so it keeps the app's locale.
    expect(($this->mails)('marke_b')[0]->getOriginalMessage()->getSubject())
        ->toStartWith('Please confirm your subscription');

    // And the app locale is where it was.
    expect(app()->getLocale())->toBe('en');
});

it('restores the mail config after a send', function (): void {
    $before = [config('mail.from.address'), config('mail.from.name'), config('marketing.from.email')];

    $list = ($this->list)($this->brandA, 'liste-a');

    BrandContext::runFor($this->brandA, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'anna@example.com'));

    expect([config('mail.from.address'), config('mail.from.name'), config('marketing.from.email')])
        ->toBe($before);
});

it('sends a campaign as the brand the message row belongs to', function (): void {
    $list = BrandContext::runFor($this->brandA, function () {
        app(MailingListRepository::class)->save(new MailingList(
            handle: 'liste-a',
            name: 'Liste A',
            doubleOptIn: false,
        ));

        app(CampaignRepository::class)->save(new Campaign(
            handle: 'kampagne-a',
            name: 'Kampagne A',
            subject: 'Neues von uns',
            listHandle: 'liste-a',
            content: '<p>Hallo.</p>',
        ));

        return app(MailingListRepository::class)->find('liste-a');
    });

    BrandContext::runFor($this->brandA, function () use ($list) {
        app(SubscriptionService::class)->subscribe($list, 'anna@example.com');

        app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('kampagne-a'));
    });

    expect(($this->mails)('marke_a'))->toHaveCount(1)
        ->and(($this->mails)('marke_b'))->toHaveCount(0)
        ->and(($this->mails)('global'))->toHaveCount(0);

    expect(($this->mails)('marke_a')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-a.test');
});

it('sends a CP test mail as the brand in context', function (): void {
    BrandContext::runFor($this->brandA, function () {
        app(MailingListRepository::class)->save(new MailingList(
            handle: 'liste-a',
            name: 'Liste A',
            doubleOptIn: false,
        ));

        app(CampaignRepository::class)->save(new Campaign(
            handle: 'kampagne-a',
            name: 'Kampagne A',
            subject: 'Neues von uns',
            listHandle: 'liste-a',
            content: '<p>Hallo.</p>',
        ));

        app(CampaignSender::class)->sendTest(
            app(CampaignRepository::class)->find('kampagne-a'),
            'redaktion@marke-a.test',
        );
    });

    expect(($this->mails)('marke_a'))->toHaveCount(1)
        ->and(($this->mails)('global'))->toHaveCount(0);
});

it('behaves exactly as before for a brand that declares no mail settings', function (): void {
    $plain = Brand::create(['handle' => 'schlicht', 'name' => 'Schlicht']);

    $list = ($this->list)($plain, 'liste-c');

    BrandContext::runFor($plain, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'carla@example.com'));

    // The configured mailer, the configured From, the app's locale — the
    // single-brand install is untouched by all of this.
    expect(($this->mails)('global'))->toHaveCount(1)
        ->and(($this->mails)('marke_a'))->toHaveCount(0);

    $message = ($this->mails)('global')[0]->getOriginalMessage();

    expect($message->getFrom()[0]->getAddress())->toBe('global@example.com')
        ->and($message->getFrom()[0]->getName())->toBe('Global');
});

it('never burns a brand\'s From into a cached mailer instance', function (): void {
    // The regression this guards: Laravel's MailManager reads `mail.from` the
    // first time a mailer name is resolved and keeps it on the instance for the
    // life of the process (`alwaysFrom`). Overriding `mail.from.*` inside the
    // send window therefore escapes the window — the first brand to send would
    // leave its address standing for every later message through that transport
    // that sets no From of its own.
    //
    // Brand A's send is deliberately the first thing in this test that touches
    // any mailer, which is the order that made it dangerous.
    $list = ($this->list)($this->brandA, 'liste-a');

    BrandContext::runFor($this->brandA, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'anna@example.com'));

    Mail::mailer('marke_a')->raw('Hallo', function ($message): void {
        $message->to('irgendwer@example.com')->subject('Ohne eigenen Absender');
    });

    $mails = ($this->mails)('marke_a');

    expect($mails)->toHaveCount(2)
        ->and($mails[1]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@example.com'); // the host's own from, not brand A's
});

it('sends nothing at all when a brand names a mailer but no address', function (): void {
    // Until 12.08.2026 this package was the odd one out: it kept the brand's
    // transport and put the host-wide From on the message, which is the pair a
    // relay verifying sending domains per account either refuses or rewrites to
    // an identity that is not this brand's. The three sibling packages already
    // refused it. The strictest reading wins, and this is where it is written
    // down.
    Log::spy();

    $halb = Brand::create([
        'handle' => 'halb',
        'name' => 'Halb',
        'settings' => ['mail' => ['mailer' => 'marke_b']],
    ]);

    $list = ($this->list)($halb, 'liste-d');

    BrandContext::runFor($halb, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'dora@example.com'));

    // A second send for the same brand says nothing more: the line is
    // throttled, or a fan-out would write one copy per recipient.
    BrandContext::runFor($halb, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'egon@example.com'));

    expect(($this->mails)('marke_b'))->toHaveCount(0)
        ->and(($this->mails)('global'))->toHaveCount(0);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $m) => str_contains($m, 'halb') && str_contains($m, 'from_address'))
        ->once();

    // And the subscription stays `pending` rather than 500-ing behind a public
    // form: a person who is registered as unconfirmed and never gets a link is
    // a worse outcome than one whose link is late.
    expect(BrandContext::runFor($halb, fn () => Subscription::query()->where('email', 'dora@example.com')->exists()))
        ->toBeTrue();
});

it('sends nothing when a brand names a mailer config/mail.php does not define', function (): void {
    $tippfehler = Brand::create([
        'handle' => 'tippfehler',
        'name' => 'Tippfehler',
        'settings' => ['mail' => ['from_address' => 'x@tippfehler.test', 'mailer' => 'scaleway_typo']],
    ]);

    $list = ($this->list)($tippfehler, 'liste-e');

    BrandContext::runFor($tippfehler, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'frida@example.com'));

    expect(($this->mails)('global'))->toHaveCount(0)
        ->and(($this->mails)('marke_a'))->toHaveCount(0)
        ->and(($this->mails)('marke_b'))->toHaveCount(0);
});

it('lets the brand address win over the one the campaign names', function (): void {
    // The rule the sibling packages already had, and this one did not: the
    // address is half of a pair with the transport, and only the brand row
    // knows which addresses the relay account behind that transport owns.
    Log::spy();

    $list = BrandContext::runFor($this->brandA, function () {
        app(MailingListRepository::class)->save(new MailingList(
            handle: 'liste-a',
            name: 'Liste A',
            doubleOptIn: false,
        ));

        app(CampaignRepository::class)->save(new Campaign(
            handle: 'kampagne-a',
            name: 'Kampagne A',
            subject: 'Neues von uns',
            listHandle: 'liste-a',
            content: '<p>Hallo.</p>',
            fromEmail: 'redaktion@woanders.test',
            fromName: 'Redaktion',
        ));

        return app(MailingListRepository::class)->find('liste-a');
    });

    BrandContext::runFor($this->brandA, function () use ($list) {
        app(SubscriptionService::class)->subscribe($list, 'anna@example.com');

        app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('kampagne-a'));
    });

    // Two mails through marke_a: the confirmation is off (doubleOptIn: false),
    // so this is the campaign alone.
    expect(($this->mails)('marke_a')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('noreply@marke-a.test');

    Log::shouldHaveReceived('notice')
        ->withArgs(fn (string $m) => str_contains($m, 'redaktion@woanders.test')
            && str_contains($m, 'noreply@marke-a.test'))
        ->once();
});

it('still honours the campaign address where no brand declares one', function (): void {
    // Every single-brand install. Nothing about this changed, and that is the
    // whole reason the rule above is safe to apply.
    $plain = Brand::create(['handle' => 'schlicht', 'name' => 'Schlicht']);

    $list = BrandContext::runFor($plain, function () {
        app(MailingListRepository::class)->save(new MailingList(
            handle: 'liste-c',
            name: 'Liste C',
            doubleOptIn: false,
        ));

        app(CampaignRepository::class)->save(new Campaign(
            handle: 'kampagne-c',
            name: 'Kampagne C',
            subject: 'Neues von uns',
            listHandle: 'liste-c',
            content: '<p>Hallo.</p>',
            fromEmail: 'redaktion@schlicht.test',
            fromName: 'Redaktion',
        ));

        return app(MailingListRepository::class)->find('liste-c');
    });

    BrandContext::runFor($plain, function () use ($list) {
        app(SubscriptionService::class)->subscribe($list, 'carla@example.com');

        app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('kampagne-c'));
    });

    expect(($this->mails)('global')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('redaktion@schlicht.test');
});

it('refuses a queued mailable rather than losing the identity on the way', function (): void {
    $queued = new class extends Mailable implements ShouldQueue
    {
        public function build(): self
        {
            return $this->subject('Egal')->html('<p>Egal</p>');
        }
    };

    expect(fn () => app(BrandMailer::class)->send($this->brandA->id, 'anna@example.com', null, $queued))
        ->toThrow(LogicException::class);
});

it('lets the host application answer the question its own way', function (): void {
    app()->bind(SenderIdentityResolver::class, fn () => new class implements SenderIdentityResolver
    {
        public function resolve(?int $brandId): SenderIdentity
        {
            return SenderIdentity::of('marke_b', 'host@example.com', 'Host');
        }
    });

    $list = ($this->list)($this->brandA, 'liste-a');

    BrandContext::runFor($this->brandA, fn () => app(SubscriptionService::class)
        ->subscribe($list, 'anna@example.com'));

    expect(($this->mails)('marke_b'))->toHaveCount(1)
        ->and(($this->mails)('marke_a'))->toHaveCount(0);

    expect(($this->mails)('marke_b')[0]->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('host@example.com');
});
