<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;

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
 * The response may not tell the attacker anything. If a withheld mail produced
 * a different status, body or redirect, the limit would become a way to ask
 * "does this address already exist on your list" — which is the question the
 * suppression gate above it also refuses to answer.
 */
it('answers a withheld attempt exactly as it answers a sent one', function (): void {
    $erste = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'opfer@gmail.com',
    ]);

    $zweite = $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter', 'email' => 'opfer@gmail.com',
    ]);

    Mail::assertSent(ConfirmSubscriptionMail::class, 1);

    expect($zweite->status())->toBe($erste->status())
        ->and($zweite->json())->toBe($erste->json());
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

    anmelden('opfer@gmail.com')->assertRedirect();

    Mail::assertNothingSent();

    // And the row survives, so the person is recoverable once the cache is back.
    expect(Subscription::query()->where('email', 'opfer@gmail.com')->first()->status)
        ->toBe(Subscription::STATUS_PENDING);
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

it('can be switched off where the endpoint is not public', function (): void {
    config()->set('marketing.subscriptions.confirmation_throttle.enabled', false);

    anmelden('opfer@gmail.com');
    anmelden('opfer@gmail.com');

    Mail::assertSent(ConfirmSubscriptionMail::class, 2);
});
