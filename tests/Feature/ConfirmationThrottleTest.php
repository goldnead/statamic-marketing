<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The mail cannon, closed.
 *
 * Everything in front of this counts senders — the websites throttle per
 * client IP, the hub per brand at 120 requests a minute. None of them can see
 * that all 120 are pointed at one person, so the effective ceiling on a single
 * victim was 120 confirmation mails a minute, sent from a verified domain that
 * belongs to Adrian.
 *
 * These tests drive the PUBLIC form, not the service, because the form is the
 * road an attacker actually walks.
 */
beforeEach(function (): void {
    Mail::fake();

    /*
     * A counter of this test's own, so nothing leaks between tests and the
     * suite does not depend on which cache store happens to be configured.
     *
     * `swap()` rather than a container binding: Statamic calls
     * `RateLimiter::for()` while booting, which resolves the facade and caches
     * the instance statically. A later `app()->instance()` rebinds the
     * container and the facade never notices — the first draft of this file
     * did exactly that, and the fail-closed test below quietly went on
     * measuring the default array store instead of the null one.
     */
    RateLimiterFacade::swap(new RateLimiter(new Repository(new ArrayStore)));

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: true,
    ));
});

function anmelden(string $email, string $list = 'newsletter')
{
    return test()->post(route('marketing.subscribe'), ['list' => $list, 'email' => $email]);
}

it('sends one confirmation mail however often the form is submitted', function (): void {
    foreach (range(1, 20) as $ignored) {
        anmelden('opfer@gmail.com')->assertRedirect();
    }

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);
});

/**
 * The heart of it. Each of these reaches the same inbox, and each of them was
 * a separate bucket under any limit keyed on the address as written.
 */
it('counts every spelling of one mailbox as one mailbox', function (): void {
    anmelden('opfer@gmail.com');
    anmelden('Opfer@Gmail.com');
    anmelden('opfer+1@gmail.com');
    anmelden('opfer+2@gmail.com');
    anmelden('o.p.f.e.r@gmail.com');
    anmelden('opfer@googlemail.com');

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);
});

it('still lets a different person subscribe', function (): void {
    anmelden('opfer@gmail.com');
    anmelden('jemand.anderes@example.com');

    Mail::assertSent(ConfirmSubscriptionMail::class, 2);
});

/**
 * The second tier. Without it the per-list limit is walked across lists and
 * the ceiling climbs straight back up.
 */
it('bounds one mailbox across every list at once', function (): void {
    foreach (range(1, 8) as $n) {
        app(MailingListRepository::class)->save(new MailingList(
            handle: 'liste-'.$n,
            name: 'Liste '.$n,
            doubleOptIn: true,
        ));
    }

    foreach (range(1, 8) as $n) {
        anmelden('opfer@gmail.com', 'liste-'.$n);
    }

    // per_mailbox defaults to 5 a day, and the lists cannot be used to exceed it.
    Mail::assertSent(ConfirmSubscriptionMail::class, 5);
});

it('lets the mailbox through again once the window has passed', function (): void {
    anmelden('opfer@gmail.com');
    Mail::assertSent(ConfirmSubscriptionMail::class, 1);

    $this->travel(61)->minutes();

    anmelden('opfer@gmail.com');
    Mail::assertSent(ConfirmSubscriptionMail::class, 2);
});

/**
 * Until 2.5.0 a withheld attempt was byte-identical to a sent one, and the
 * visitor was told to check an inbox that stayed empty. On 13.08.2026 that
 * happened to a real sign-up on gldnr.studio: success on screen, the mail held
 * back by this throttle, the row still `pending` the next morning.
 *
 * The answer now says which of the two it was. It is a statement about a moment
 * in time — somebody asked for this mailbox recently — and the tests below
 * pin down that it stays that and never becomes a statement about a person.
 */
it('says when it withheld, instead of promising a mail that is not coming', function (): void {
    $erste = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'opfer@gmail.com',
    ]);

    $zweite = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'opfer@gmail.com',
    ]);

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);

    expect($erste->json('data.confirmation'))->toBe('sent')
        ->and($zweite->json('data.confirmation'))->toBe('throttled')
        // The configured window, not the time left on the counter: the
        // remainder would say how long ago the earlier attempt was, to the
        // second.
        ->and($zweite->json('data.retry_after_minutes'))->toBe(60)
        ->and($zweite->status())->toBe($erste->status());
});

/**
 * The oracle this change could have built, and did not.
 *
 * An address already on the list gets no mail — there is nothing to confirm.
 * If that path answered `sent` forever while an ordinary address flipped to
 * `throttled` on the second try, then two submissions would tell a stranger
 * whether somebody is subscribed. Two submissions is not an attack, it is a
 * double-click.
 *
 * So the silent path is charged the same budget and produces the same pair of
 * answers in the same order.
 */
it('cannot be asked whether an address is already subscribed', function (): void {
    $subscribed = 'schon.drin@gmail.com';

    // Get one address genuinely subscribed, then clear the counters so both
    // sequences below start from the same place. `Cache::flush()` and not a
    // limiter swap: `ConfirmationThrottle` builds its own limiter on the store
    // it is configured with, precisely so that it never depends on what the
    // facade happens to be wired to.
    anmelden($subscribed);
    app(SubscriptionService::class)->markSubscribed(
        Subscription::query()->where('email', $subscribed)->first()
    );
    Cache::flush();

    $drinErste = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => $subscribed]);
    $drinZweite = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => $subscribed]);

    $neuErste = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => 'ganz.neu@gmail.com']);
    $neuZweite = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => 'ganz.neu@gmail.com']);

    expect($drinErste->json())->toBe($neuErste->json())
        ->and($drinZweite->json())->toBe($neuZweite->json())
        ->and($drinZweite->json('data.confirmation'))->toBe('throttled');
});

/**
 * The same question asked about suppression. "Has this mailbox bounced or
 * complained" is a fact about a person's behaviour and may not be answerable
 * from a public form — which is why the gate is silent, and why the budget is
 * charged BEFORE it rather than after. Charged after, the gate would return
 * before the counter moved and the sequences would diverge.
 */
it('cannot be asked whether an address is suppressed', function (): void {
    $gesperrt = 'gesperrt@gmail.com';

    app(SuppressionService::class)->suppress($gesperrt, Reasons::HARD_BOUNCE);

    $sperrErste = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => $gesperrt]);
    $sperrZweite = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => $gesperrt]);

    $neuErste = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => 'ganz.neu@gmail.com']);
    $neuZweite = $this->postJson(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => 'ganz.neu@gmail.com']);

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);

    expect($sperrErste->json())->toBe($neuErste->json())
        ->and($sperrZweite->json())->toBe($neuZweite->json());
});

/**
 * The status field that used to sit in this response is gone, in both shapes.
 * It answered `subscribed` against `pending` — the membership question, handed
 * out for free on an endpoint anybody may post to.
 */
it('no longer hands out the subscription status', function (): void {
    $antwort = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'opfer@gmail.com',
    ]);

    expect($antwort->json('data'))->not->toHaveKey('status')
        ->and(json_encode($antwort->json()))->not->toContain('pending');
});

it('keeps the pending subscription when it withholds the mail', function (): void {
    anmelden('opfer@gmail.com');
    anmelden('opfer+2@gmail.com');

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);

    // Two rows, because consent is recorded per address as given — only the
    // MAIL was withheld, and each person's stated wish is still on file.
    expect(Subscription::query()->count())->toBe(2)
        ->and(Subscription::query()->where('email', 'opfer+2@gmail.com')->first()->status)
        ->toBe(Subscription::STATUS_PENDING);
});

/**
 * A withheld attempt must not disturb the link the victim is actually holding.
 * If it rotated the token, typing a stranger's address into the public form
 * would become a way to break their pending sign-up — an abuse limit that
 * opens a second, quieter kind of abuse.
 */
it('leaves the live confirmation link alone when it withholds', function (): void {
    anmelden('opfer@gmail.com');

    $vorher = Subscription::query()->where('email', 'opfer@gmail.com')->first()->confirmation_token;

    anmelden('opfer@gmail.com');
    anmelden('opfer+stoerung@gmail.com');

    $nachher = Subscription::query()->where('email', 'opfer@gmail.com')->first()->confirmation_token;

    expect($nachher)->toBe($vorher);
});

/**
 * A limiter on a cache that stores nothing counts to zero forever and permits
 * everything — silently. That is not hypothetical: it is exactly how the
 * webhook path shipped a log throttle that could be walked straight past. On a
 * public, anonymous endpoint, not being able to count is a reason to stop.
 */
it('refuses to send when the counter cannot count', function (): void {
    RateLimiterFacade::swap(new RateLimiter(new Repository(new NullStore)));
    config()->set('cache.stores.marketing-test', ['driver' => 'null']);
    config()->set('marketing.subscriptions.confirmation_throttle.store', 'marketing-test');

    $antwort = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'opfer@gmail.com',
    ]);

    Mail::assertNothingSent();

    /*
     * And it says so. A broken counter withholds EVERY mail, for every address
     * alike, so there is nothing about anybody to disclose — while staying
     * silent means every sign-up in the outage is told to check an empty inbox
     * and nobody finds out until somebody reads the log.
     */
    expect($antwort->json('data.confirmation'))->toBe('unavailable')
        ->and($antwort->json('data'))->not->toHaveKey('retry_after_minutes');

    // And the row survives, so the person is recoverable once the cache is back.
    expect(Subscription::query()->where('email', 'opfer@gmail.com')->first()->status)
        ->toBe(Subscription::STATUS_PENDING);
});

/**
 * A brand that names a sender identity and breaks half of it sends nothing —
 * `BrandMailer` answers `false` rather than throwing. Until 2.5.0 that `false`
 * was dropped on the floor here and the visitor got the success page anyway;
 * HUB.md described that as intended, which it was for the row and was not for
 * the promise on the screen.
 */
it('says the installation could not send when the transport refuses', function (): void {
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new TransportException(
        'Expected response code "250/251/252" but got code "501".'
    ));

    $antwort = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'abgelehnt@example.invalid',
    ]);

    expect($antwort->json('data.confirmation'))->toBe('unavailable');
});

/**
 * The store check, which is the part that decides whether any of this is a
 * limit at all.
 *
 * `file` is Laravel's DEFAULT cache driver, and its `increment()` reads a
 * file, adds one and writes it back — three steps with no lock, reporting
 * success whether or not the write landed. Parallel sign-ups all read the same
 * number and all pass it; an unwritable cache directory returns 1 forever.
 * Assuming production is on Redis is not the same as knowing it, so the class
 * refuses anything it cannot count on.
 */
it('refuses a cache store that cannot increment atomically', function (): void {
    config()->set('cache.stores.marketing-test', [
        'driver' => 'file',
        'path' => storage_path('framework/cache/marketing-test'),
    ]);
    config()->set('marketing.subscriptions.confirmation_throttle.store', 'marketing-test');

    anmelden('opfer@gmail.com')->assertRedirect();

    Mail::assertNothingSent();
});

it('counts on a store that does increment atomically', function (): void {
    config()->set('cache.stores.marketing-test', ['driver' => 'array']);
    config()->set('marketing.subscriptions.confirmation_throttle.store', 'marketing-test');

    anmelden('opfer@gmail.com');
    anmelden('opfer+2@gmail.com');

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);
});

/**
 * A relay that refuses the recipient may not become a 500 on a public form.
 *
 * Found against the live system, not in review: `x@example.invalid` passes
 * `email:rfc,filter` and every check this addon makes, and Scaleway answers
 * `501 5.1.3 … not a valid RFC-5322 address` at RCPT time. Symfony's SMTP
 * client throws, and before this the exception went all the way out — an
 * anonymous endpoint answering a typo with a stack trace, after the pending
 * row was already written.
 */
it('answers rather than throwing when the transport refuses the recipient', function (): void {
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new TransportException(
        'Expected response code "250/251/252" but got code "501".'
    ));

    anmelden('abgelehnt@example.invalid')->assertRedirect();

    // Und die Zeile steht, damit ein zweiter Versuch sie noch retten kann.
    expect(Subscription::query()->where('email', 'abgelehnt@example.invalid')->first()->status)
        ->toBe(Subscription::STATUS_PENDING);
});

it('can be switched off where the endpoint is not public', function (): void {
    config()->set('marketing.subscriptions.confirmation_throttle.enabled', false);

    anmelden('opfer@gmail.com');
    anmelden('opfer@gmail.com');

    Mail::assertSent(ConfirmSubscriptionMail::class, 2);
});
