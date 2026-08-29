<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Insights\ClickRate;
use Goldnead\Marketing\Integrations\Insights\MailsSent;
use Goldnead\Marketing\Integrations\Insights\MarketingMetric;
use Goldnead\Marketing\Integrations\Insights\OpenRate;
use Goldnead\Marketing\Integrations\Insights\Subscribed;
use Goldnead\Marketing\Integrations\Insights\SubscribersActive;
use Goldnead\Marketing\Integrations\Insights\Unsubscribed;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The six numbers this addon offers the analytics addon.
 *
 * Every expectation below is worked out by hand from one small fixture, and the
 * fixture holds every awkward case on purpose: a sign-up that never confirmed,
 * a subscriber who left, a confirmation from before the window, a failed send
 * with no date, a mail that belongs to no campaign, and — the one that matters
 * most — an open that was Apple's proxy rather than a person.
 *
 * `CampaignStats`, `CampaignReport` and `SubscriptionGrowth` are untouched by
 * all of it. They answer "how did this campaign do" and "is the list growing";
 * these answer "what happened between two dates", and the rates here are
 * deliberately a stricter reading than the ones every archived campaign was
 * measured with.
 *
 * Time is frozen. The buckets are asserted as literal dates, and a suite that
 * ran across midnight would otherwise fail once a night for reasons that have
 * nothing to do with the code.
 */

/** The day everything below is measured from. */
const MARKETING_HEUTE = '2026-08-20 12:00:00';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse(MARKETING_HEUTE));

    // The definitions, through the repository rather than the table. Under the
    // flat driver there is no table to write to, and the split below has to
    // find a name either way — which is the whole reason it does not join.
    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Wochenpost'));
    app(MailingListRepository::class)->save(new MailingList(handle: 'kurse', name: 'Kursbrief'));
    app(CampaignRepository::class)->save(new Campaign(handle: 'august', name: 'August-Ausgabe'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// -- The fixture -------------------------------------------------------------

/**
 * Six subscriptions and five mails, all of it addable in the head.
 *
 * Confirmations: one before the window, three inside it. Departures: one.
 * Sends: four that went out, one that failed and therefore has no date.
 */
function marketingInsightsFixture(): array
{
    // Confirmed in July: in the stock from the start, in no arrival figure.
    marketingSubscription('alt@example.com', 'newsletter', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'subscribed_at' => '2026-07-01 09:00:00',
        'confirmed_at' => '2026-07-01 09:05:00',
    ]);

    $anna = marketingSubscription('anna@example.com', 'newsletter', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'subscribed_at' => '2026-08-12 10:00:00',
        'confirmed_at' => '2026-08-12 10:10:00',
    ]);

    marketingSubscription('bruno@example.com', 'newsletter', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'subscribed_at' => '2026-08-14 08:00:00',
        'confirmed_at' => '2026-08-14 08:30:00',
    ]);

    marketingSubscription('clara@example.com', 'kurse', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'subscribed_at' => '2026-08-14 19:00:00',
        'confirmed_at' => '2026-08-14 19:02:00',
    ]);

    // Confirmed on the 15th and gone again on the 18th.
    marketingSubscription('dora@example.com', 'newsletter', [
        'status' => Subscription::STATUS_UNSUBSCRIBED,
        'subscribed_at' => '2026-08-15 11:00:00',
        'confirmed_at' => '2026-08-15 11:05:00',
        'unsubscribed_at' => '2026-08-18 09:00:00',
    ]);

    // Filled in the form and never clicked the link. Not a subscriber, and not
    // in a single figure here.
    marketingSubscription('zoe@example.com', 'newsletter', [
        'status' => Subscription::STATUS_PENDING,
        'subscribed_at' => '2026-08-16 12:00:00',
        'confirmed_at' => null,
    ]);

    // Four mails out on the 17th, one of which failed and has no date.
    $gelesen = marketingMessage($anna, 'august', '2026-08-17 09:00:00');
    $geklickt = marketingMessage($anna, 'august', '2026-08-17 09:00:00', 'bruno@example.com');
    $maschine = marketingMessage($anna, 'august', '2026-08-17 09:00:00', 'clara@example.com');
    marketingMessage($anna, null, '2026-08-19 14:00:00', 'einzeln@example.com');

    marketingMessage($anna, 'august', null, 'fehlgeschlagen@example.com', [
        'status' => Message::STATUS_FAILED,
        'error' => 'relay refused',
    ]);

    // A person opened it.
    marketingEvent($gelesen, MessageEvent::TYPE_OPEN, '2026-08-17 10:00:00');
    // And again, twice more. One reader, not three.
    marketingEvent($gelesen, MessageEvent::TYPE_OPEN, '2026-08-17 11:00:00');
    marketingEvent($gelesen, MessageEvent::TYPE_OPEN, '2026-08-18 08:00:00');

    // Somebody whose client blocks images and who followed a link: a reader
    // with no open event at all.
    marketingEvent($geklickt, MessageEvent::TYPE_CLICK, '2026-08-17 12:00:00', ['url' => 'https://example.com']);

    // Apple's proxy fetching the pixel on delivery. Not a person, and the whole
    // reason this rate is not the one on the campaign screen.
    marketingEvent($maschine, MessageEvent::TYPE_OPEN, '2026-08-17 09:01:00', ['machine' => true]);

    return compact('gelesen', 'geklickt', 'maschine');
}

function marketingSubscription(string $email, string $list, array $overrides = []): Subscription
{
    return Subscription::create(array_merge([
        'list_handle' => $list,
        'email' => $email,
        'status' => Subscription::STATUS_PENDING,
    ], $overrides));
}

function marketingMessage(Subscription $subscription, ?string $campaign, ?string $sentAt, ?string $email = null, array $overrides = []): Message
{
    return Message::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'campaign_handle' => $campaign,
        'subscription_id' => $subscription->id,
        'email' => $email ?? $subscription->email,
        'status' => $sentAt === null ? Message::STATUS_PENDING : Message::STATUS_SENT,
        'sent_at' => $sentAt,
    ], $overrides));
}

function marketingEvent(Message $message, string $type, string $at, array $overrides = []): MessageEvent
{
    return MessageEvent::create(array_merge([
        'message_id' => $message->id,
        'type' => $type,
        'machine' => false,
        'created_at' => $at,
        'updated_at' => $at,
    ], $overrides));
}

/** The ten days the fixture lives in, bucketed by day. */
function marketingQuery(array $filters = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
{
    return new MetricQuery(
        Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
        $bucket,
        $filters,
    );
}

/**
 * A split as a map, so an expectation does not depend on the order of two rows
 * that hold the same number.
 *
 * @return array<string, int|float>
 */
function marketingKeyed(array $rows): array
{
    $keyed = [];

    foreach ($rows as $row) {
        $keyed[$row['key'] ?? ''] = $row['value'];
    }

    ksort($keyed);

    return $keyed;
}

/** @return array<int, MarketingMetric> */
function marketingMetrics(): array
{
    return [new Subscribed, new Unsubscribed, new SubscribersActive, new MailsSent, new OpenRate, new ClickRate];
}

// -- The six figures ---------------------------------------------------------

/**
 * Every figure at once, against hand-worked totals.
 *
 * One test rather than six, deliberately: they are read side by side on a
 * screen and have to agree with each other. Four confirmations against four
 * subscribers is the pair worth catching — it is only right because one from
 * July is still there and one from the 15th has since left.
 */
it('reports the six figures the campaign screens cannot', function (): void {
    marketingInsightsFixture();
    $frage = marketingQuery();

    expect((new Subscribed)->value($frage))->toBe(4, 'anna, bruno, clara and dora; zoe never confirmed');
    expect((new Unsubscribed)->value($frage))->toBe(1, 'dora, on the day she left');
    expect((new SubscribersActive)->value($frage))->toBe(4, 'the July one plus anna, bruno and clara');

    expect((new MailsSent)->value($frage))->toBe(4, 'the failed one has no date and was never sent');

    // Two of the four sends were read by a person: one who opened it three
    // times and one who only clicked. The proxy's fetch is not a reader.
    expect((new OpenRate)->value($frage))->toBe(50.0, 'round(2 / 4 * 100, 1)');
    expect((new ClickRate)->value($frage))->toBe(25.0, 'round(1 / 4 * 100, 1)');
});

/**
 * The machine open is the whole point, so it is asserted on its own.
 *
 * Apple's Mail Privacy Protection fetches the pixel for every message it
 * delivers, whether anybody looks or not. A rate that counted it would say
 * three of four mails were read; three quarters of the difference between this
 * figure and the campaign screen's is that one row.
 */
it('does not count a proxy as a reader', function (): void {
    $fixture = marketingInsightsFixture();

    expect((new OpenRate)->value(marketingQuery()))->toBe(50.0);

    // Turn the same event into a human one and the rate moves by exactly one
    // mail, which is the proof that nothing else is doing the work.
    DB::table('marketing_message_events')
        ->where('message_id', $fixture['maschine']->id)
        ->update(['machine' => false]);

    expect((new OpenRate)->value(marketingQuery()))->toBe(75.0);
});

/** The handles are a contract. They end up in saved dashboards and in URLs. */
it('keeps the handles, units and group it promised', function (): void {
    $erwartet = [
        [Subscribed::class, 'marketing.subscribed', Unit::COUNT],
        [Unsubscribed::class, 'marketing.unsubscribed', Unit::COUNT],
        [SubscribersActive::class, 'marketing.subscribers_active', Unit::COUNT],
        [MailsSent::class, 'marketing.mails_sent', Unit::COUNT],
        [OpenRate::class, 'marketing.open_rate', Unit::PERCENT],
        [ClickRate::class, 'marketing.click_rate', Unit::PERCENT],
    ];

    foreach ($erwartet as [$klasse, $handle, $unit]) {
        $metrik = new $klasse;

        expect($metrik->handle())->toBe($handle);
        expect($metrik->unit())->toBe($unit);
        expect($metrik->group())->toBe(__('marketing::insights.group'));
        expect($metrik->label())->not->toBe('');
        expect($metrik->description())->not->toBeEmpty();

        // No unit here needs anything the formatter does not already have.
        expect($metrik->meta(marketingQuery()))->toBe([]);
    }
});

it('names the group the same way in every metric and every language', function (): void {
    expect(__('marketing::insights.group'))->toBe('Newsletter');

    app()->setLocale('de');
    expect(__('marketing::insights.group'))->toBe('Newsletter');
});

// -- Registration ------------------------------------------------------------

it('offers every metric to the analytics addon under its handle', function (): void {
    expect($this->insights->registered)->toMatchArray([
        'marketing.subscribed' => Subscribed::class,
        'marketing.unsubscribed' => Unsubscribed::class,
        'marketing.subscribers_active' => SubscribersActive::class,
        'marketing.mails_sent' => MailsSent::class,
        'marketing.open_rate' => OpenRate::class,
        'marketing.click_rate' => ClickRate::class,
    ]);
});

// -- Nothing to measure ------------------------------------------------------

/**
 * No tables, no answer — and not a zero.
 *
 * "Nothing to measure" and "measured nothing" are different statements, and a
 * zero for the first is the quiet kind of wrong: it puts a confident 0 on a
 * dashboard for a site that has never run these migrations.
 */
it('cannot answer without the tables', function (): void {
    foreach (marketingMetrics() as $metrik) {
        expect($metrik->available())->toBeTrue($metrik->handle().' should be available here');
    }

    // A second, empty database rather than dropping the tables in this one.
    // Dropping them would leave the suite unable to roll its own migrations
    // back, and a test that breaks its neighbours' teardown reports the wrong
    // failure everywhere afterwards.
    config()->set('database.connections.ohne_marketing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $vorher = DB::getDefaultConnection();
    DB::purge('ohne_marketing');
    DB::setDefaultConnection('ohne_marketing');

    try {
        foreach (marketingMetrics() as $metrik) {
            expect($metrik->available())->toBeFalse($metrik->handle().' answered without its table');
            expect($metrik->value(marketingQuery()))->toBeNull();
            expect($metrik->series(marketingQuery()))->toBe([]);
        }
    } finally {
        DB::setDefaultConnection($vorher);
    }
});

/**
 * The storage driver decides where the definitions live, and nothing else.
 *
 * It looks as though it should gate these figures and it does not: mailing
 * lists, campaigns and templates may be YAML, but subscriptions, messages and
 * their events are always in the database — the config file says so in as many
 * words. What the driver does change is the **labels**, and that is asserted
 * separately below.
 *
 * The whole suite runs twice, once per driver, so this test proves the same
 * thing from both sides in one run of CI.
 */
it('answers under either storage driver', function (): void {
    marketingInsightsFixture();

    foreach (marketingMetrics() as $metrik) {
        expect($metrik->available())->toBeTrue($metrik->handle().' fell silent on the '.config('marketing.storage.driver').' driver');
    }

    expect((new Subscribed)->value(marketingQuery()))->toBe(4);
    expect((new MailsSent)->value(marketingQuery()))->toBe(4);
});

/**
 * The open rate is defined by a column, so it is unavailable without it.
 *
 * An install that has not run the 2026-08-15 migration cannot tell a person
 * from a proxy. Reporting the looser number under the stricter name would be
 * the exact confusion this figure exists to remove.
 */
it('will not state an open rate on an install that cannot tell a proxy from a person', function (): void {
    marketingInsightsFixture();

    expect((new OpenRate)->available())->toBeTrue();

    // Taken away and put back, rather than dropped and left. RefreshDatabase
    // rolls this database back by running the migrations' `down()`, and a
    // column that is already gone makes the teardown throw — which reports the
    // wrong failure in every test that follows.
    Schema::table('marketing_message_events', fn (Blueprint $table) => $table->dropColumn('machine'));

    try {
        expect((new OpenRate)->available())->toBeFalse();
        expect((new OpenRate)->value(marketingQuery()))->toBeNull();
        expect((new OpenRate)->series(marketingQuery()))->toBe([]);

        // The click rate does not read the column and keeps answering: a proxy
        // fetches images, it does not follow links.
        expect((new ClickRate)->available())->toBeTrue();
        expect((new ClickRate)->value(marketingQuery()))->toBe(25.0);
    } finally {
        Schema::table('marketing_message_events', fn (Blueprint $table) => $table->boolean('machine')->default(false));
    }
});

// -- A rate with no denominator ----------------------------------------------

/**
 * A rate against nothing is a question, not a small number.
 *
 * "0 % opened" is a statement about mails that were never sent, and it would
 * sit on the screen directly beside a send count of zero that disproves it.
 */
it('has no rate at all in a period that sent nothing', function (): void {
    marketingInsightsFixture();

    $leer = new MetricQuery(
        Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-13')->endOfDay()),
    );

    expect((new MailsSent)->value($leer))->toBe(0, 'nothing went out in those three days');
    expect((new OpenRate)->value($leer))->toBeNull('so there is no rate to state');
    expect((new ClickRate)->value($leer))->toBeNull();

    // And its neighbours do answer, because "nothing was sent" is not an answer
    // to what they ask.
    expect((new Subscribed)->value($leer))->toBe(1, 'anna confirmed on the 12th');
});

/**
 * Per bucket, and `null` where there is no denominator — not a gap.
 *
 * A day on which nothing went out has no rate. Left out of the series it would
 * be filled with a zero by Insights and drawn as a collapse; handed over as
 * `null` it stays null all the way to a chart that draws no bar at all.
 */
it('hands back a null bucket rather than a zero one', function (): void {
    marketingInsightsFixture();

    expect((new OpenRate)->series(marketingQuery()))->toBe([
        '2026-08-17' => 66.7,
        '2026-08-19' => 0.0,
    ]);

    // The 19th sent one mail nobody opened, which is a real nought. A day that
    // sent nothing has no bucket here at all.
    expect((new MailsSent)->series(marketingQuery()))->toBe([
        '2026-08-17' => 3,
        '2026-08-19' => 1,
    ]);
});

// -- The stock ---------------------------------------------------------------

/**
 * The stock is what stood at the end, not what happened during.
 *
 * Dora confirmed on the 15th and left on the 18th, so she is in the count on
 * the 15th, 16th and 17th and out of it from the 18th. A figure that read her
 * current status would have taken her out of the 15th as well, and every past
 * month would move a little every time somebody unsubscribes.
 */
it('counts the stock at the end of each bucket, including the quiet ones', function (): void {
    marketingInsightsFixture();

    expect((new SubscribersActive)->series(marketingQuery()))->toBe([
        '2026-08-11' => 1,
        '2026-08-12' => 2,
        '2026-08-13' => 2,
        '2026-08-14' => 4,
        '2026-08-15' => 5,
        // Zoe signs up on the 16th and never confirms: not a subscriber.
        '2026-08-16' => 5,
        '2026-08-17' => 5,
        '2026-08-18' => 4,
        '2026-08-19' => 4,
        '2026-08-20' => 4,
    ]);

    expect((new SubscribersActive)->series(marketingQuery()))->toHaveCount(10);
});

it('counts the stock monthly when the question is monthly', function (): void {
    marketingInsightsFixture();

    $monatlich = new MetricQuery(
        Period::between(Carbon::parse('2026-07-01')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
        MetricQuery::BUCKET_MONTH,
    );

    expect((new SubscribersActive)->series($monatlich))->toBe([
        '2026-07' => 1,
        '2026-08' => 4,
    ]);
});

/** Nobody at all is an empty chart, not a flat line reaching back to the epoch. */
it('draws no stock series at all when nobody has confirmed', function (): void {
    expect((new SubscribersActive)->value(marketingQuery()))->toBe(0);
    expect((new SubscribersActive)->series(new MetricQuery(Period::fromPreset('all'))))->toBe([]);
});

// -- Over time ---------------------------------------------------------------

it('puts each flow figure on the day it happened', function (): void {
    marketingInsightsFixture();
    $frage = marketingQuery();

    expect((new Subscribed)->series($frage))->toBe([
        '2026-08-12' => 1,
        '2026-08-14' => 2,
        '2026-08-15' => 1,
    ]);

    expect((new Unsubscribed)->series($frage))->toBe(['2026-08-18' => 1]);
});

// -- The future -------------------------------------------------------------

/**
 * "All time" has no upper bound, and this addon's tables hold the future.
 *
 * A campaign scheduled for Friday, a send deferred by the frequency cap: without
 * a clamp the widest range reports every one of them as a fact of the past.
 */
it('keeps the future out of the widest range', function (): void {
    marketingInsightsFixture();

    $spaeter = marketingSubscription('morgen@example.com', 'newsletter', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'subscribed_at' => '2026-09-15 09:00:00',
        'confirmed_at' => '2026-09-15 09:00:00',
    ]);

    marketingMessage($spaeter, 'august', '2026-09-15 10:00:00', 'morgen@example.com');

    $alles = new MetricQuery(Period::fromPreset('all'), MetricQuery::BUCKET_MONTH);

    expect((new Subscribed)->value($alles))->toBe(5, 'the five that happened, not the sixth that has not');
    expect((new MailsSent)->value($alles))->toBe(4);

    // And the stock is asked as of this moment, not as of the end of time.
    expect((new SubscribersActive)->value($alles))->toBe(4);
});

/**
 * The last second of the window is in the window, and midnight is not.
 *
 * The upper bound is 23:59:59.999999, and a database binding formats it as
 * `Y-m-d H:i:s` and drops the fraction — so a `<=` comparison threw away every
 * row in the final second on any column carrying one. These columns are whole
 * seconds today, which is exactly why this is asserted rather than assumed: the
 * day a migration adds precision to one of them, nothing else would notice.
 */
it('keeps the last second of the window and gives midnight to the next one', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-21 06:00:00'));

    marketingSubscription('sekunde@example.com', 'newsletter', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'confirmed_at' => '2026-08-20 23:59:59',
    ]);

    marketingSubscription('mitternacht@example.com', 'newsletter', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'confirmed_at' => '2026-08-21 00:00:00',
    ]);

    expect((new Subscribed)->value(marketingQuery()))->toBe(1, 'the last second is inside, midnight is not');
    expect((new Subscribed)->series(marketingQuery()))->toBe(['2026-08-20' => 1]);

    $folgetag = new MetricQuery(
        Period::between(Carbon::parse('2026-08-21')->startOfDay(), Carbon::parse('2026-08-21')->endOfDay()),
    );

    expect((new Subscribed)->value($folgetag))->toBe(1, 'and midnight opens the next period');
});

// -- One clock ---------------------------------------------------------------

/**
 * The addon and the report keep time the same way, and this is where that is
 * proven rather than assumed.
 *
 * Every date column read here goes through Laravel's `datetime` cast, which
 * stores and reads in the application's timezone; Insights builds its `Period`
 * from `Carbon::now()`, the same clock. An addon that stored UTC behind a cast
 * of its own would be five hours out at every period boundary on a site in
 * Chicago, and the figures would be wrong only for the rows near the edges —
 * the hardest kind of wrong to notice.
 */
it('reads the same clock as the report it reports to', function (): void {
    $vorher = date_default_timezone_get();
    config()->set('app.timezone', 'America/Chicago');
    date_default_timezone_set('America/Chicago');

    try {
        // Late on the last evening of the window, so the row at half past
        // eleven is in the past and only the timezone can move it.
        Carbon::setTestNow(Carbon::parse('2026-08-20 23:55:00'));

        marketingSubscription('spaet@example.com', 'newsletter', [
            'status' => Subscription::STATUS_SUBSCRIBED,
            'confirmed_at' => '2026-08-20 23:30:00',
        ]);

        marketingSubscription('frueh@example.com', 'newsletter', [
            'status' => Subscription::STATUS_SUBSCRIBED,
            'confirmed_at' => '2026-08-11 00:15:00',
        ]);

        expect((new Subscribed)->value(marketingQuery()))->toBe(2, 'both edges are inside the window');

        expect((new Subscribed)->series(marketingQuery()))->toBe([
            '2026-08-11' => 1,
            '2026-08-20' => 1,
        ]);
    } finally {
        date_default_timezone_set($vorher);
    }
});

// -- The splits --------------------------------------------------------------

/**
 * A handle in a column, a name on the screen — and through the repository.
 *
 * A join against `marketing_lists` would find nothing under the flat driver,
 * where the definitions are YAML files and the table stands empty. The split
 * would then come out labelled with handles on every flat-file installation
 * while looking perfectly correct on the developer's machine. This suite runs
 * under both drivers, which is what makes the assertion worth anything.
 */
it('labels a list and a campaign with their names, not their handles', function (): void {
    marketingInsightsFixture();
    $frage = marketingQuery();

    $listen = collect((new Subscribed)->breakdown($frage, 'list_handle'))->keyBy('key');

    expect($listen['newsletter']['label'])->toBe('Wochenpost');
    expect($listen['newsletter']['value'])->toBe(3, 'anna, bruno and dora');
    expect($listen['kurse']['label'])->toBe('Kursbrief');

    $kampagnen = collect((new MailsSent)->breakdown($frage, 'campaign_handle'))->keyBy('key');

    expect($kampagnen['august']['label'])->toBe('August-Ausgabe');
    expect($kampagnen['august']['value'])->toBe(3);

    // A handle nothing knows keeps its handle rather than vanishing.
    app(CampaignRepository::class)->delete('august');

    $ohneNamen = collect((new MailsSent)->breakdown($frage, 'campaign_handle'))->keyBy('key');
    expect($ohneNamen['august']['label'])->toBe('august');
});

/**
 * A mail belonging to no campaign is a row, not an omission.
 *
 * `campaign_handle` has been nullable since the template send mode arrived: a
 * managed template sent to one person belongs to no campaign. Dropping those
 * rows would make the split disagree with the total, with nothing on the screen
 * to say why.
 */
it('gives the mails outside any campaign a row of their own', function (): void {
    marketingInsightsFixture();

    $zeilen = (new MailsSent)->breakdown(marketingQuery(), 'campaign_handle');

    expect(marketingKeyed($zeilen))->toBe(['' => 1, 'august' => 3]);
    expect(array_sum(array_column($zeilen, 'value')))->toBe(4, 'the split has to add up to the figure it splits');

    $ohne = collect($zeilen)->firstWhere('key', null);
    expect($ohne['label'])->toBe(__('marketing::insights.missing.campaign_handle'));
});

it('offers exactly the splits it can answer', function (): void {
    marketingInsightsFixture();

    expect(array_keys((new Subscribed)->breakdowns()))->toBe(['list_handle']);
    expect(array_keys((new Unsubscribed)->breakdowns()))->toBe(['list_handle']);
    expect(array_keys((new MailsSent)->breakdowns()))->toBe(['campaign_handle']);

    // A split nobody offers is empty, not an error.
    expect((new Subscribed)->breakdown(marketingQuery(), 'weather'))->toBe([]);
    expect((new MailsSent)->breakdown(marketingQuery(), 'list_handle'))->toBe([]);
});

// -- One brand at a time -----------------------------------------------------

/**
 * These queries run past the global scope, so the brand is decided in the open.
 *
 * `DB::table()` does not carry the `HasBrand` scope every model in this addon
 * has, which is not an oversight to be worked around: a Control Panel screen has
 * an active brand and a scheduled command has none, and the two need different
 * answers.
 */
it('counts one brand at a time when the install has more than one', function (): void {
    marketingInsightsFixture();

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $zweite = Brand::create(['handle' => 'insights-b', 'name' => 'Zweite Marke']);

    BrandContext::setCurrent($zweite);
    marketingSubscription('fremd@example.com', 'zweite-liste', [
        'status' => Subscription::STATUS_SUBSCRIBED,
        'confirmed_at' => '2026-08-13 10:00:00',
    ]);

    expect((new Subscribed)->value(marketingQuery()))->toBe(1);
    expect((new SubscribersActive)->value(marketingQuery()))->toBe(1);

    BrandContext::setCurrent(Brand::default());

    expect((new Subscribed)->value(marketingQuery()))->toBe(4);
    expect((new SubscribersActive)->value(marketingQuery()))->toBe(4);
});

/**
 * No brand in hand is a nought, and the tile stays where it is.
 *
 * This test used to assert the opposite, and the opposite was wrong. A command
 * line has no active brand, and the scope's own answer for the *rows* in that
 * state is `fail_mode` — `closed` meaning none of them. This addon answered it
 * with `available() === false`, which took all six of its tiles off the screen;
 * LeadHub did the same, so twelve tiles disappeared at once and not one of them
 * said why.
 *
 * `available()` says whether there is anything to measure — the tables, the
 * `machine` column, a sibling. A brand nobody has picked yet is none of those.
 * So the rows are refused, the count reads nought, the rate has no denominator
 * and stays null, and every tile keeps its place.
 */
it('reads nought and keeps its place when no brand is resolved', function (): void {
    marketingInsightsFixture();

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();
    BrandContext::setCurrent(null);

    foreach (marketingMetrics() as $metrik) {
        expect($metrik->available())->toBeTrue(
            $metrik->handle().' left the screen over a brand rather than over its tables',
        );
    }

    expect((new Subscribed)->value(marketingQuery()))->toBe(0);
    expect((new Unsubscribed)->value(marketingQuery()))->toBe(0);
    expect((new MailsSent)->value(marketingQuery()))->toBe(0);

    // The stock reads its own un-windowed queries, so the brand has to reach
    // those as well — this is the one that would have summed silently. Every
    // bucket a nought, and not one of them a null.
    expect((new SubscribersActive)->value(marketingQuery()))->toBe(0);
    expect(array_unique(array_values((new SubscribersActive)->series(marketingQuery()))))->toBe([0]);

    // Nothing sent is no denominator, which is a statement about the mails and
    // not about the brand.
    expect((new OpenRate)->value(marketingQuery()))->toBeNull();
    expect((new ClickRate)->value(marketingQuery()))->toBeNull();

    expect((new Subscribed)->series(marketingQuery()))->toBe([]);

    // Where the install has said it prefers the other answer, the metric reads
    // across brands — the same thing the scope does with `fail_mode: open`.
    config()->set('brand-context.fail_mode', 'open');
    app('brand-context')->forget();
    BrandContext::setCurrent(null);

    expect((new Subscribed)->available())->toBeTrue();
    expect((new Subscribed)->value(marketingQuery()))->toBe(4);
    expect((new SubscribersActive)->value(marketingQuery()))->toBe(4);
});
