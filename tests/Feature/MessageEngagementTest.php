<?php

/**
 * What became of one set of mails, counted in people.
 *
 * The automations map asks this for every mail of a series. The two properties
 * that matter: a proxy's open is not a person's (Apple's Mail Privacy Protection
 * opens everything on delivery), and a person is counted once however many
 * messages or events they left. A click is a person, and it implies the open.
 */

use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\MessageEngagement;

beforeEach(function (): void {
    $this->mail = function (string $email, array $events = [], string $status = Message::STATUS_SENT, string $template = 'tag-1'): Message {
        $subscription = Subscription::firstOrCreate(
            ['list_handle' => 'newsletter', 'email' => strtolower($email)],
            ['status' => Subscription::STATUS_SUBSCRIBED],
        );

        $message = Message::create([
            'template_handle' => $template,
            'subscription_id' => $subscription->id,
            'email' => $email,
            'status' => $status,
            'sent_at' => $status === Message::STATUS_SENT ? now() : null,
        ]);

        foreach ($events as [$type, $machine]) {
            MessageEvent::create(['message_id' => $message->id, 'type' => $type, 'machine' => $machine]);
        }

        return $message;
    };
});

it('counts people by what they did, a proxy open apart from a person', function (): void {
    $uuids = [
        ($this->mail)('nur-gesendet@example.com')->uuid,
        ($this->mail)('proxy@example.com', [[MessageEvent::TYPE_OPEN, true]])->uuid,
        ($this->mail)('gelesen@example.com', [[MessageEvent::TYPE_OPEN, true], [MessageEvent::TYPE_OPEN, false]])->uuid,
        ($this->mail)('geklickt@example.com', [[MessageEvent::TYPE_OPEN, true], [MessageEvent::TYPE_CLICK, false]])->uuid,
    ];

    expect(app(MessageEngagement::class)->forMessages($uuids))->toBe([
        'sent' => 4,
        'opened' => 2,
        'proxy_only' => 1,
        'clicked' => 1,
        'unsubscribed' => 0,
    ]);
});

it('counts a person once however many opens, clicks and messages they left', function (): void {
    $uuids = [
        ($this->mail)('Maria@example.com', [[MessageEvent::TYPE_OPEN, false], [MessageEvent::TYPE_OPEN, false], [MessageEvent::TYPE_CLICK, false], [MessageEvent::TYPE_CLICK, false]])->uuid,
        ($this->mail)('maria@example.com', [[MessageEvent::TYPE_OPEN, false]])->uuid,
    ];

    expect(app(MessageEngagement::class)->forMessages($uuids))->toMatchArray([
        'sent' => 1,
        'opened' => 1,
        'clicked' => 1,
    ]);
});

it('does not count a mail that was never sent, and ignores mails outside the set', function (): void {
    $kept = ($this->mail)('a@example.com', [[MessageEvent::TYPE_OPEN, false]])->uuid;
    ($this->mail)('b@example.com', [[MessageEvent::TYPE_OPEN, false]], Message::STATUS_FAILED);
    $failed = ($this->mail)('c@example.com', [], Message::STATUS_FAILED)->uuid;
    ($this->mail)('d@example.com', [[MessageEvent::TYPE_CLICK, false]], Message::STATUS_SENT, 'tag-2');

    expect(app(MessageEngagement::class)->forMessages([$kept, $failed]))->toBe([
        'sent' => 1,
        'opened' => 1,
        'proxy_only' => 0,
        'clicked' => 0,
        'unsubscribed' => 0,
    ]);
});

it('counts the people who unsubscribed from this mail', function (): void {
    $uuids = [
        ($this->mail)('geht@example.com', [[MessageEvent::TYPE_OPEN, false], [MessageEvent::TYPE_UNSUBSCRIBE, false]])->uuid,
        ($this->mail)('bleibt@example.com')->uuid,
    ];

    expect(app(MessageEngagement::class)->forMessages($uuids))->toMatchArray(['sent' => 2, 'unsubscribed' => 1]);
});

it('answers an empty set with zeros and reads a large set in chunks', function (): void {
    expect(app(MessageEngagement::class)->forMessages([]))->toBe([
        'sent' => 0, 'opened' => 0, 'proxy_only' => 0, 'clicked' => 0, 'unsubscribed' => 0,
    ]);

    // More uuids than a SQLite statement takes variables.
    $uuids = [($this->mail)('x@example.com', [[MessageEvent::TYPE_CLICK, false]])->uuid];
    for ($i = 0; $i < 1500; $i++) {
        $uuids[] = "00000000-0000-4000-8000-{$i}";
    }

    expect(app(MessageEngagement::class)->forMessages($uuids))->toMatchArray(['sent' => 1, 'clicked' => 1]);
});

it('writes nothing', function (): void {
    $uuid = ($this->mail)('r@example.com', [[MessageEvent::TYPE_OPEN, true]])->uuid;
    $before = [Message::count(), MessageEvent::count(), Message::first()->opens];

    app(MessageEngagement::class)->forMessages([$uuid]);

    expect([Message::count(), MessageEvent::count(), Message::first()->opens])->toBe($before);
});
