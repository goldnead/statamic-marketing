<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\MessageBounced;
use Goldnead\Marketing\Events\MessageClicked;
use Goldnead\Marketing\Events\MessageOpened;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Integrations\Leadhub\TimelineRecorder;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\TrackingService;

/**
 * Every mail, on the recipient's contact record.
 *
 * The two addons were married underneath and mute to each other on screen. The
 * facts were all recorded — in `marketing_messages`, keyed by message, where
 * nobody looking at a *person* would ever find them.
 *
 * Two properties carry the weight here and neither is about the happy path: a
 * tracking pixel must not be able to create a CRM record, and nothing on this
 * path may turn a delivered mail into an error.
 */
beforeEach(function (): void {
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo aus dem Chor',
    ));

    $this->recorder = app(TimelineRecorder::class);

    $this->message = function (string $email = 'maria@example.com'): Message {
        $subscription = Subscription::create([
            'list_handle' => 'newsletter',
            'email' => $email,
            'status' => Subscription::STATUS_SUBSCRIBED,
        ]);

        return Message::create([
            'campaign_handle' => 'brief',
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    };

    $this->contact = fn (string $email = 'maria@example.com') => Contact::create(['email' => $email]);

    $this->entries = fn () => Event::query()->where('type', 'like', 'marketing.%')->get();
});

it('writes a sent mail onto the contact timeline', function (): void {
    ($this->contact)();

    $this->recorder->sent(new MessageSent(($this->message)()));

    $entries = ($this->entries)();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe(TimelineRecorder::TYPE_SENT)
        ->and($entries[0]->summary)->toContain('Hallo aus dem Chor');
});

it('writes nothing for an address with no contact', function (): void {
    // The load-bearing one. A pixel on a mail to somebody who signed up and
    // never confirmed must not conjure a CRM record for them.
    $this->recorder->sent(new MessageSent(($this->message)('fremd@example.com')));

    expect(($this->entries)())->toHaveCount(0)
        ->and(Contact::query()->count())->toBe(0);
});

it('marks an open that a machine made as exactly that', function (): void {
    // Apple Mail loads the image for every message it delivers. Recorded as a
    // reading, it says somebody looked at a mail nobody opened.
    ($this->contact)();

    $this->recorder->opened(new MessageOpened(($this->message)(), ['machine' => true]));

    expect(($this->entries)()[0]->type)->toBe(TimelineRecorder::TYPE_PREFETCHED);
});

it('records a human open as an open', function (): void {
    ($this->contact)();

    $this->recorder->opened(new MessageOpened(($this->message)(), ['machine' => false]));

    expect(($this->entries)()[0]->type)->toBe(TimelineRecorder::TYPE_OPENED);
});

it('records a click, which is the entry that means a person did something', function (): void {
    ($this->contact)();

    $this->recorder->clicked(new MessageClicked(($this->message)(), ['url' => 'https://example.com']));

    expect(($this->entries)()[0]->type)->toBe(TimelineRecorder::TYPE_CLICKED);
});

it('records a bounce', function (): void {
    ($this->contact)();

    $this->recorder->bounced(new MessageBounced(($this->message)(), ['hard' => true]));

    expect(($this->entries)()[0]->type)->toBe(TimelineRecorder::TYPE_BOUNCED);
});

it('writes one entry however often the same fact is reported', function (): void {
    // A retried job, a pixel fetched twice: the timeline must not turn one
    // reading into two.
    ($this->contact)();
    $message = ($this->message)();

    $this->recorder->opened(new MessageOpened($message, ['machine' => false]));
    $this->recorder->opened(new MessageOpened($message, ['machine' => false]));
    $this->recorder->opened(new MessageOpened($message, ['machine' => false]));

    expect(($this->entries)())->toHaveCount(1);
});

it('hands the timeline readable lines, not just a payload dump', function (): void {
    ($this->contact)();

    $this->recorder->sent(new MessageSent(($this->message)()));

    $detail = collect(($this->entries)()[0]->payload['detail'] ?? [])->pluck('value');

    expect($detail)->toContain('Hallo aus dem Chor')
        ->and($detail)->toContain('Der Brief')
        ->and($detail)->toContain('newsletter');
});

it('explains a prefetched open in the entry itself', function (): void {
    // The note is the whole point of separating the two kinds: whoever reads
    // this row has to know the mail was not necessarily read.
    ($this->contact)();

    $this->recorder->opened(new MessageOpened(($this->message)(), ['machine' => true]));

    $detail = collect(($this->entries)()[0]->payload['detail'] ?? [])->pluck('value')->implode(' ');

    expect($detail)->toContain('Apple');
});

it('never lets the CRM turn a delivered mail into an error', function (): void {
    // This hangs off the send path and off two public tracking endpoints.
    ($this->contact)();

    LeadHub::swap(new class
    {
        public function findByEmail(string $email): array
        {
            throw new RuntimeException('the CRM is mid-upgrade');
        }
    });

    expect(fn () => $this->recorder->sent(new MessageSent(($this->message)())))
        ->not->toThrow(Throwable::class);
});

it('can be switched off entirely', function (): void {
    config()->set('marketing.timeline.enabled', false);
    ($this->contact)();

    $this->recorder->sent(new MessageSent(($this->message)()));

    expect(($this->entries)())->toHaveCount(0);
});

it('can be narrowed to the kinds an installation wants', function (): void {
    // Fifty thousand recipients and a row per open on every contact is a
    // legitimate thing to not want.
    config()->set('marketing.timeline.types', [TimelineRecorder::TYPE_CLICKED]);
    ($this->contact)();
    $message = ($this->message)();

    $this->recorder->sent(new MessageSent($message));
    $this->recorder->opened(new MessageOpened($message, ['machine' => false]));
    $this->recorder->clicked(new MessageClicked($message, ['url' => 'https://example.com']));

    $entries = ($this->entries)();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe(TimelineRecorder::TYPE_CLICKED);
});

it('reads as a whole sentence when the campaign is gone', function (): void {
    // A message outlives the campaign it belonged to. "Mail versendet: " with a
    // dangling colon and a trailing space is the kind of small wrongness that
    // makes a screen look unfinished — and `not->toEndWith(':')` would pass for
    // exactly that string, which is why this pins the whole sentence instead.
    ($this->contact)();

    $message = ($this->message)();
    $message->forceFill(['campaign_handle' => 'geloescht', 'template_handle' => null])->save();

    $this->recorder->sent(new MessageSent($message));

    expect(($this->entries)()[0]->summary)
        ->toBe((string) __('marketing::timeline.sent_untitled'))
        ->not->toContain(':');
});

it('says somebody read it, even after a scanner fetched the image first', function (): void {
    // The expensive case. For a mailbox behind Mimecast or Proofpoint the first
    // open is practically always the machine's, `MessageOpened` has fired and
    // will not fire again, and without the second event the record would say
    // "preloaded" forever and never once say the person read it.
    ($this->contact)();
    $message = ($this->message)();

    app(TrackingService::class)->recordOpen($message->uuid, 'Mimecast MTA');
    app(TrackingService::class)->recordOpen($message->uuid, 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0');

    $types = ($this->entries)()->pluck('type');

    expect($types)->toContain(TimelineRecorder::TYPE_PREFETCHED)
        ->and($types)->toContain(TimelineRecorder::TYPE_OPENED);
});

it('says it once, however often the person opens it again', function (): void {
    ($this->contact)();
    $message = ($this->message)();
    $human = 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0';

    app(TrackingService::class)->recordOpen($message->uuid, $human);
    app(TrackingService::class)->recordOpen($message->uuid, $human);
    app(TrackingService::class)->recordOpen($message->uuid, $human);

    expect(($this->entries)()->where('type', TimelineRecorder::TYPE_OPENED))->toHaveCount(1);
});

it('survives a campaign file it cannot read', function (): void {
    // Constraint 2 in its real shape: the summary resolves the campaign, and a
    // corrupt file must not escape onto the send path or a public tracking
    // endpoint. Built as an argument to `record()` it would have been evaluated
    // outside the guard entirely.
    ($this->contact)();

    app()->bind(CampaignRepository::class, fn () => new class
    {
        public function find(string $handle): mixed
        {
            throw new RuntimeException('unreadable campaign file');
        }
    });

    expect(fn () => $this->recorder->sent(new MessageSent(($this->message)())))
        ->not->toThrow(Throwable::class);
});

it('stays quiet on a LeadHub too old to have the methods', function (): void {
    // Not a failure to report — an installation without the feature. Without
    // this the first send against such an install would warn once per recipient
    // about a method that was simply never there.
    LeadHub::swap(new class
    {
        public function nothing(): void {}
    });

    expect(TimelineRecorder::available())->toBeFalse();

    ($this->contact)();

    expect(fn () => $this->recorder->sent(new MessageSent(($this->message)())))
        ->not->toThrow(Throwable::class);

    expect(($this->entries)())->toHaveCount(0);
});
