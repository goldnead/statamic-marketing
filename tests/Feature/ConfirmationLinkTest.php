<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;

/**
 * The confirmation link: what it opens, once, and for how long.
 *
 * Before this, one `token` column answered for confirm, unsubscribe and the
 * preference centre alike. It was written once and never rotated, and
 * `confirmByToken()` refused a row that was already subscribed but said
 * nothing about one that had unsubscribed. So the link in a mail from two
 * years ago — still in the mailbox, still in the backup, still in every link
 * scanner's history — put a person who had left back on the list, with no act
 * of theirs and no new consent to point at.
 */
beforeEach(function (): void {
    Mail::fake();

    // Swapped, not rebound — see ConfirmationThrottleTest for why the
    // container alone is not enough here.
    RateLimiterFacade::swap(new RateLimiter(new Repository(new ArrayStore)));

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: true,
    ));
});

function angemeldet(string $email = 'jane@example.com'): Subscription
{
    test()->post(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => $email]);

    return Subscription::query()->where('email', $email)->firstOrFail();
}

/**
 * The scanner test. Outlook SafeLinks, gateway virus scanners and messenger
 * previews all fetch every URL in an incoming mail.
 */
it('grants nothing when the link is merely opened', function (): void {
    $sub = angemeldet();

    $this->get(route('marketing.confirm', $sub->confirmation_token))->assertOk();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_PENDING)
        ->and($sub->fresh()->confirmation_used_at)->toBeNull();
});

it('grants the subscription when the button is pressed', function (): void {
    $sub = angemeldet();

    $this->post(route('marketing.confirm.post', $sub->confirmation_token))->assertOk();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

/**
 * THE fix. An old confirmation link may not undo an unsubscribe.
 */
it('refuses a confirmation link that predates an unsubscribe', function (): void {
    $sub = angemeldet();
    $link = $sub->confirmation_token;

    $this->post(route('marketing.confirm.post', $link))->assertOk();
    expect($sub->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);

    app(SubscriptionService::class)->unsubscribe($sub->fresh());
    expect($sub->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);

    // The same link, months later, out of a backup or a scanner's history.
    $this->get(route('marketing.confirm', $link))->assertNotFound();
    $this->post(route('marketing.confirm.post', $link))->assertNotFound();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('refuses a link issued before an unsubscribe even if it was never used', function (): void {
    $sub = angemeldet();
    $link = $sub->confirmation_token;

    app(SubscriptionService::class)->unsubscribe($sub->fresh());

    $this->post(route('marketing.confirm.post', $link))->assertNotFound();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

/**
 * Single use, and single use in the sense that matters: the link grants
 * consent exactly once. A repeat click still finds its row and still says
 * something true, but changes nothing.
 */
it('spends the link on first use and grants nothing on the second', function (): void {
    $sub = angemeldet();
    $link = $sub->confirmation_token;

    $this->post(route('marketing.confirm.post', $link))->assertOk();

    $ersteBestaetigung = $sub->fresh()->confirmed_at;

    $this->travel(5)->minutes();
    $this->post(route('marketing.confirm.post', $link))
        ->assertOk()
        ->assertSee(__('marketing::public.confirmed_title'));

    // Not re-stamped: the second press did not run the confirmation again.
    expect($sub->fresh()->confirmed_at->timestamp)->toBe($ersteBestaetigung->timestamp);
});

it('rotates the link every time a new confirmation mail goes out', function (): void {
    $sub = angemeldet();
    $alt = $sub->confirmation_token;

    // Past the per-list window, so a second mail is genuinely sent.
    $this->travel(61)->minutes();
    angemeldet();

    $neu = $sub->fresh()->confirmation_token;

    expect($neu)->not->toBe($alt);

    $this->post(route('marketing.confirm.post', $alt))->assertNotFound();
    $this->post(route('marketing.confirm.post', $neu))->assertOk();
});

it('retires a link that was never used', function (): void {
    $sub = angemeldet();

    $this->travel(169)->hours();

    $this->get(route('marketing.confirm', $sub->confirmation_token))->assertStatus(410);
    $this->post(route('marketing.confirm.post', $sub->confirmation_token))->assertStatus(410);

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_PENDING);
});

/**
 * The two keys are separate now, and the confirm route knows only one of them.
 * The long-lived token is printed in the footer of every campaign; anyone
 * forwarded such a mail holds it.
 */
it('does not accept the long-lived unsubscribe token as a confirmation', function (): void {
    $sub = angemeldet();

    $this->get(route('marketing.confirm', $sub->token))->assertNotFound();
    $this->post(route('marketing.confirm.post', $sub->token))->assertNotFound();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_PENDING);
});

it('leaves the unsubscribe token untouched when the confirmation link rotates', function (): void {
    $sub = angemeldet();
    $unsub = $sub->token;

    $this->post(route('marketing.confirm.post', $sub->confirmation_token))->assertOk();

    // Footers of campaigns already sent keep working — that is the whole
    // reason the two values were separated instead of rotating the one.
    expect($sub->fresh()->token)->toBe($unsub);

    $this->get(route('marketing.unsubscribe', $unsub))->assertOk();
    expect($sub->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

/**
 * Every row carries a confirmation token, because the column is NOT NULL and
 * its unique has to constrain something. That is not the same as having a live
 * link, and the difference has to hold: a token that was never mailed is a
 * string that has never left the database.
 */
it('refuses a token that was never mailed to anybody', function (): void {
    // The first sign-up spends the mailbox's hour; the second is the same
    // mailbox under a tag, so its row is written and its mail withheld.
    angemeldet();

    $this->post(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => 'jane+tag@example.com']);

    $ungemailt = Subscription::query()->where('email', 'jane+tag@example.com')->firstOrFail();

    expect($ungemailt->confirmation_token)->not->toBeEmpty()
        ->and($ungemailt->confirmation_sent_at)->toBeNull()
        ->and($ungemailt->status)->toBe(Subscription::STATUS_PENDING);

    $this->get(route('marketing.confirm', $ungemailt->confirmation_token))->assertNotFound();
    $this->post(route('marketing.confirm.post', $ungemailt->confirmation_token))->assertNotFound();

    expect($ungemailt->fresh()->status)->toBe(Subscription::STATUS_PENDING);
});

/**
 * The front door to the hole this release closes.
 *
 * `confirmByToken()` refuses a link whose row is not pending — but `status` is
 * writable by anybody, from a public form, by typing somebody else's address.
 * So the refusal can be walked round: get the row back to `pending`, and the
 * old link is live again. It works precisely BECAUSE the mail is withheld —
 * a withheld send returns before the token would be rotated.
 */
it('cannot be re-armed by posting the address again after an unsubscribe', function (): void {
    $sub = angemeldet();
    $link = $sub->confirmation_token;

    $this->post(route('marketing.confirm.post', $link))->assertOk();
    app(SubscriptionService::class)->unsubscribe($sub->fresh());

    // The stranger's move: submit the victim's address, which flips the row
    // back to pending. The mail is withheld — the mailbox has had its one for
    // this hour — so nothing new is issued.
    $this->post(route('marketing.subscribe'), ['list' => 'newsletter', 'email' => 'jane@example.com']);

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_PENDING);

    // And the old link is still dead.
    $this->get(route('marketing.confirm', $link))->assertNotFound();
    $this->post(route('marketing.confirm.post', $link))->assertNotFound();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_PENDING)
        ->and($sub->fresh()->confirmed_at)->not->toBeNull();
});

it('can be put back to one click where an install wants that', function (): void {
    config()->set('marketing.subscriptions.confirm_requires_post', false);

    $sub = angemeldet();

    $this->get(route('marketing.confirm', $sub->confirmation_token))->assertOk();

    expect($sub->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});
