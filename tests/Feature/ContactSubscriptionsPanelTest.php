<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Leadhub\ContactSubscriptionsPanel;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\ServiceProvider;
use Statamic\Facades\User;

/**
 * "Which mailing lists is this person on?", answered on LeadHub's contact page.
 *
 * The two addons were married underneath and invisible to each other on screen:
 * a contact record showed tags, tasks and a timeline and said nothing about the
 * newsletter the person had been getting for a year. Contributed from this side
 * — marketing requires leadhub, not the other way round.
 */

/** Run the provider's own registration step, whatever LeadHub is swapped in. */
function registerPanelThroughProvider(): void
{
    $provider = app()->getProvider(ServiceProvider::class);

    $method = new ReflectionMethod($provider, 'registerContactPanel');
    $method->setAccessible(true);
    $method->invoke($provider);
}

beforeEach(function (): void {
    $user = User::make()->email('panel@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    $lists = app(MailingListRepository::class);
    $lists->save(new MailingList(handle: 'newsletter', name: 'Der Chorleiter-Brief'));

    $this->panel = fn ($contact) => app(ContactSubscriptionsPanel::class)($contact);

    $this->subscribe = function (string $email, string $status, string $list = 'newsletter'): Subscription {
        return Subscription::create([
            'list_handle' => $list,
            'email' => $email,
            'status' => $status,
            'subscribed_at' => now()->subMonths(3),
            'unsubscribed_at' => $status === Subscription::STATUS_UNSUBSCRIBED ? now()->subDay() : null,
        ]);
    };
});

it('lists the person\'s subscriptions with their status', function (): void {
    ($this->subscribe)('maria@example.com', Subscription::STATUS_SUBSCRIBED);

    $panel = ($this->panel)(Contact::create(['email' => 'maria@example.com']));

    expect($panel['rows'])->toHaveCount(1)
        ->and($panel['rows'][0]['label'])->toBe('Der Chorleiter-Brief')
        ->and($panel['rows'][0]['badge']['color'])->toBe('green');
});

it('shows the list name, not the handle it is stored under', function (): void {
    ($this->subscribe)('maria@example.com', Subscription::STATUS_SUBSCRIBED);

    expect(($this->panel)(Contact::create(['email' => 'maria@example.com']))['rows'][0]['label'])
        ->toBe('Der Chorleiter-Brief');
});

it('falls back to the handle when the list is gone', function (): void {
    // A subscription outlives the list it points at — deleting a list must not
    // turn this panel into a blank row or an error.
    ($this->subscribe)('maria@example.com', Subscription::STATUS_SUBSCRIBED, 'deleted-list');

    expect(($this->panel)(Contact::create(['email' => 'maria@example.com']))['rows'][0]['label'])
        ->toBe('deleted-list');
});

it('includes a sign-up that has not confirmed yet', function (): void {
    // The reason this matches on the address rather than on `contact_uuid`:
    // the uuid is only filled once a subscription is confirmed and synced, and
    // an unconfirmed sign-up is precisely what somebody opens this page to check.
    $pending = ($this->subscribe)('maria@example.com', Subscription::STATUS_PENDING);
    $pending->forceFill(['contact_uuid' => null])->save();

    $rows = ($this->panel)(Contact::create(['email' => 'maria@example.com']))['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['badge']['color'])->toBe('amber');
});

it('matches the address the way consent does, not character by character', function (): void {
    ($this->subscribe)('Maria+chor@Example.com', Subscription::STATUS_SUBSCRIBED);

    expect(($this->panel)(Contact::create(['email' => 'maria+chor@example.com']))['rows'])
        ->toHaveCount(1);
});

it('says so when the person is on no list', function (): void {
    $panel = ($this->panel)(Contact::create(['email' => 'nobody@example.com']));

    expect($panel['rows'])->toBe([])
        ->and($panel['empty'])->not->toBeNull();
});

it('returns nothing at all for a contact with no address', function (): void {
    // Not the same as "on no list": a contact without an address cannot be on
    // one, and a panel explaining that would be noise on every such record.
    expect(($this->panel)(Contact::create(['first_name' => 'Anonymous'])))->toBeNull();
});

it('does not link to a list the reader may not open', function (): void {
    ($this->subscribe)('maria@example.com', Subscription::STATUS_SUBSCRIBED);

    $plain = User::make()->email('crm-only@example.com');
    $plain->save();
    $this->actingAs($plain);

    // A row that 403s when clicked is worse than a row that is not a link.
    expect(($this->panel)(Contact::create(['email' => 'maria@example.com']))['rows'][0]['url'])
        ->toBeNull();
});

it('registers itself with LeadHub when the registry is there', function (): void {
    // The wiring, not the content: without it the panel class is dead code and
    // nothing on either side would fail. Asserted against a stand-in rather
    // than the real registry, because this package's own dev dependency may be
    // an older LeadHub — which is exactly the case the guard below is for.
    $recorder = new class
    {
        public array $registered = [];

        public function registerContactPanel(string $key, Closure $resolver): void
        {
            $this->registered[$key] = $resolver;
        }
    };

    LeadHub::swap($recorder);

    registerPanelThroughProvider();

    expect(array_keys($recorder->registered))->toBe(['marketing.subscriptions']);

    // And the closure it registered really produces the rows, so the wiring is
    // to the panel and not to something that merely exists.
    ($this->subscribe)('maria@example.com', Subscription::STATUS_SUBSCRIBED);

    $panel = $recorder->registered['marketing.subscriptions'](Contact::create(['email' => 'maria@example.com']));

    expect($panel['rows'][0]['label'])->toBe('Der Chorleiter-Brief');
});

it('is skipped on a LeadHub too old to have the registry', function (): void {
    // `method_exists` and not a version constraint: this package requires
    // leadhub ^1.4|^2.0 and the registry arrived in 2.2, so an older one must
    // keep working with one panel missing rather than fatal on an undefined
    // method.
    LeadHub::swap(new class
    {
        public function nothing(): void {}
    });

    expect(fn () => registerPanelThroughProvider())->not->toThrow(Throwable::class);
});
