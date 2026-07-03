<?php

use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

/**
 * Campaign audience narrowing via LeadHub segments.
 *
 * Rule under test: recipients = subscribed list members ∩ segment members,
 * resolved live at send time. The segment only NARROWS; consent always comes
 * from the list subscription. No segment = whole list (backward compatible).
 */
beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subs = app(SubscriptionService::class);
    $this->segments = app(SegmentRepository::class);
});

/**
 * Subscribe an email AND create a matching LeadHub contact, linking the two by
 * contact_uuid (the join key the intersection uses).
 */
function subscribeWithContact(string $email, array $contactAttributes = []): Subscription
{
    $contact = LeadHub::create(array_merge(['email' => $email, 'status' => 'new'], $contactAttributes));

    $subscription = test()->subs->subscribe(test()->list, $email, ['first_name' => 'X']);
    $subscription->contact_uuid = $contact['uuid'];
    $subscription->save();

    return $subscription;
}

function makeCampaign(?string $segmentHandle): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'promo',
        name: 'Promo',
        subject: 'Hi {{ first_name }}',
        listHandle: 'newsletter',
        segmentHandle: $segmentHandle,
        content: '<p>Hi {{ first_name }}</p>',
    ));

    return app(CampaignRepository::class)->find('promo');
}

it('sends only to the intersection of list subscribers and segment members', function (): void {
    subscribeWithContact('jane@example.com', ['status' => 'qualified']);
    subscribeWithContact('john@example.com', ['status' => 'new']);

    $this->segments->create([
        'name' => 'Qualified',
        'handle' => 'qualified',
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
        ]],
    ]);

    app(CampaignSender::class)->queue(makeCampaign('qualified'));

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('jane@example.com'));
    expect(Message::forCampaign('promo')->count())->toBe(1);
});

it('never grants consent via the segment: a segment member who is not subscribed gets nothing', function (): void {
    // Jane is subscribed AND in the segment.
    subscribeWithContact('jane@example.com', ['status' => 'qualified']);

    // Bob is in the segment but NOT subscribed to the list.
    LeadHub::create(['email' => 'bob@example.com', 'status' => 'qualified']);

    $this->segments->create([
        'name' => 'Qualified',
        'handle' => 'qualified',
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
        ]],
    ]);

    app(CampaignSender::class)->queue(makeCampaign('qualified'));

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('jane@example.com'));
    Mail::assertNotSent(fn (CampaignMail $mail) => $mail->hasTo('bob@example.com'));
});

it('excludes subscribed members who are not in the segment', function (): void {
    subscribeWithContact('jane@example.com', ['status' => 'qualified']);
    subscribeWithContact('john@example.com', ['status' => 'new']);

    $this->segments->create([
        'name' => 'Qualified',
        'handle' => 'qualified',
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
        ]],
    ]);

    app(CampaignSender::class)->queue(makeCampaign('qualified'));

    Mail::assertNotSent(fn (CampaignMail $mail) => $mail->hasTo('john@example.com'));
});

it('sends to the whole list when no segment is set (backward compatible)', function (): void {
    subscribeWithContact('jane@example.com', ['status' => 'qualified']);
    subscribeWithContact('john@example.com', ['status' => 'new']);

    app(CampaignSender::class)->queue(makeCampaign(null));

    Mail::assertSent(CampaignMail::class, 2);
    expect(Message::forCampaign('promo')->count())->toBe(2);
});

it('excludes a subscribed member with no linked contact when a segment is set', function (): void {
    // Subscribed but no contact_uuid → cannot be in any segment.
    $this->subs->subscribe($this->list, 'orphan@example.com', ['first_name' => 'Orphan']);
    subscribeWithContact('jane@example.com', ['status' => 'qualified']);

    $this->segments->create([
        'name' => 'Qualified',
        'handle' => 'qualified',
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
        ]],
    ]);

    app(CampaignSender::class)->queue(makeCampaign('qualified'));

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertNotSent(fn (CampaignMail $mail) => $mail->hasTo('orphan@example.com'));
});

it('still honours list-level consent inside a segment (unsubscribed segment member excluded)', function (): void {
    $jane = subscribeWithContact('jane@example.com', ['status' => 'qualified']);
    $john = subscribeWithContact('john@example.com', ['status' => 'qualified']);

    // Both are in the segment, but John unsubscribed from the list.
    $this->subs->unsubscribe($john);

    $this->segments->create([
        'name' => 'Qualified',
        'handle' => 'qualified',
        'rules' => ['match' => 'all', 'conditions' => [
            ['type' => 'field', 'field' => 'status', 'operator' => 'eq', 'value' => 'qualified'],
        ]],
    ]);

    app(CampaignSender::class)->queue(makeCampaign('qualified'));

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('jane@example.com'));
    Mail::assertNotSent(fn (CampaignMail $mail) => $mail->hasTo('john@example.com'));
});
