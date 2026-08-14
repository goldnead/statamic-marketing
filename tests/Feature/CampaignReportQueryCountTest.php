<?php

/**
 * A report page costs the same whether it lists five people or fifty.
 *
 * A campaign can have fifty thousand recipients, and every tab of this report
 * shows people — with a name from the subscription, an event count from the
 * events table and a link into the CRM. Each of those is an N+1 waiting to be
 * written, and none of them shows up in a functional test: the page renders
 * correctly either way, it just takes a hundred and fifty queries to do it.
 *
 * So the property is measured rather than reasoned about. `DB::listen` counts
 * what actually reached the connection, the same page is rendered with five
 * rows and then with fifty, and the two numbers have to be equal. The absolute
 * figure is asserted too, loosely — not because a particular number is sacred,
 * but so that quietly adding a query per page has to be a decision somebody
 * writes down here.
 */

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\User;

beforeEach(function (): void {
    $user = User::make()->email('counter@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo',
        listHandle: 'newsletter',
    ));

    /**
     * A campaign with $count recipients, each of whom opened it twice — once
     * as a machine, once as a person — clicked one link and unsubscribed.
     *
     * Every row therefore has something to resolve on every tab: a name, a
     * split of open events, a click event, an unsubscribe, and a contact in the
     * CRM. A page that resolves any of those per row will show it here.
     */
    $this->campaignOf = function (int $count): void {
        Message::query()->delete();
        Subscription::query()->delete();
        Contact::query()->delete();

        for ($i = 0; $i < $count; $i++) {
            $email = "person{$i}@example.com";

            Contact::create(['email' => $email]);

            $subscription = Subscription::create([
                'list_handle' => 'newsletter',
                'email' => $email,
                'first_name' => 'Person',
                'last_name' => (string) $i,
                'status' => Subscription::STATUS_SUBSCRIBED,
            ]);

            $message = Message::create([
                'campaign_handle' => 'brief',
                'subscription_id' => $subscription->id,
                'email' => $email,
                'status' => Message::STATUS_SENT,
                'sent_at' => now()->subHour(),
                'opens' => 2,
                'clicks' => 1,
                'first_opened_at' => now()->subMinutes(30),
                'last_opened_at' => now()->subMinutes(10),
            ]);

            MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => true]);
            MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => false]);
            MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_CLICK, 'url' => 'https://example.com/kurs']);
            MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_UNSUBSCRIBE]);
        }
    };

    // One listener for the whole test, gated on which tab is being rendered.
    //
    // Not one per measurement, which is the trap this used to be in: Laravel
    // keeps every listener it is handed for the life of the application, and
    // `use (&$count)` in a loop binds them all to the *same* variable — so the
    // second measurement counted every query twice, the third three times, and
    // the numbers looked like an N+1 that was not there.
    $this->measuring = null;
    $this->queries = [];

    DB::listen(function () {
        if ($this->measuring !== null) {
            $this->queries[$this->measuring] = ($this->queries[$this->measuring] ?? 0) + 1;
        }
    });

    /** How many queries one render of one tab costs. */
    $this->queriesFor = function (string $tab) {
        $key = $tab.'@'.count($this->queries);
        $this->measuring = $key;

        test()->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('marketing.campaigns.show', ['brief', 'tab' => $tab]))
            ->assertStatus(200);

        $this->measuring = null;

        return $this->queries[$key] ?? 0;
    };
});

dataset('report tabs', ['overview', 'delivery', 'opens', 'clicks', 'unsubscribes']);

it('costs the same number of queries for fifty rows as for five', function (string $tab): void {
    ($this->campaignOf)(5);
    $small = ($this->queriesFor)($tab);

    ($this->campaignOf)(50);
    $large = ($this->queriesFor)($tab);

    // The measurement itself, first. Two zeroes are also equal, and a listener
    // that stopped counting would make this whole file pass by saying nothing.
    expect($small)->toBeGreaterThan(0);

    expect($large)->toBe(
        $small,
        "The [{$tab}] tab took {$small} queries for 5 rows and {$large} for 50. ".
        'Something on this page is resolved once per row.'
    );
})->with('report tabs');

it('keeps every tab inside a small fixed budget', function (string $tab, int $ceiling): void {
    // Measured, not guessed, on a campaign with fifty recipients (SQLite, flat
    // campaign storage — the eloquent driver adds one query, the campaign
    // itself):
    //
    //   overview      14  ten counts from CampaignStats, the variant pluck,
    //                     the human-open count and two timeline aggregates
    //   delivery       5  the error probe, the page count, the page, its
    //                     subscriptions, the contact lookup
    //   opens          5  page count, page, subscriptions, the open split,
    //                     the contact lookup
    //   clicks         6  the five above, minus the split, plus the message
    //                     hop and the URL breakdown
    //   unsubscribes   5
    //
    // The ceilings are those numbers plus room for one honest addition, and
    // nowhere near enough for anything resolved per row.
    ($this->campaignOf)(50);

    expect(($this->queriesFor)($tab))->toBeLessThanOrEqual($ceiling);
})->with([
    ['overview', 18],
    ['delivery', 9],
    ['opens', 9],
    ['clicks', 10],
    ['unsubscribes', 9],
]);
