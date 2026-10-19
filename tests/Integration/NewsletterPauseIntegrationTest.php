<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationNode;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Illuminate\Support\Facades\Mail;

/**
 * "One track at a time" against the real automations addon: the newsletter
 * leaves out whoever is inside a run of a flagged automation.
 *
 * The unit-level behaviour (override, counts, failure) is covered without the
 * addon in tests/Feature/CampaignSequencePauseTest.php. This file is the seam:
 * the real question, the real answer, the real send.
 */
beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    if (! method_exists(Automations::getFacadeRoot(), 'subjectsInPausingRuns')) {
        $this->markTestSkipped('The installed goldnead/statamic-automations predates the pause question (2.31.0).');
    }

    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $list = app(MailingListRepository::class)->find('newsletter');

    foreach (['in-welcome', 'in-minikurs', 'finished', 'in-unflagged', 'free'] as $who) {
        app(SubscriptionService::class)->subscribe($list, "{$who}@example.com");
    }

    $this->automation = function (string $handle, bool $flag): Automation {
        $automation = Automation::create(['name' => $handle, 'handle' => $handle, 'enabled' => true]);

        AutomationNode::create([
            'automation_id' => $automation->id,
            'node_key' => 't',
            'type' => 'manual',
            'config' => $flag ? ['_pauses_newsletter' => true] : [],
        ]);

        return $automation;
    };

    $this->run = fn (Automation $automation, string $email, string $status) => AutomationRun::create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'subject_key' => $email,
        'status' => $status,
    ]);
});

it('sends the issue to everybody except the people inside a flagged sequence', function (): void {
    $welcome = ($this->automation)('welcome-daily', true);
    $minikurs = ($this->automation)('minikurs-cvt-im-chor', true);
    $stimmanalyse = ($this->automation)('stimmanalyse-1', false);

    ($this->run)($welcome, 'in-welcome@example.com', AutomationRun::STATUS_WAITING);
    ($this->run)($minikurs, 'in-minikurs@example.com', AutomationRun::STATUS_WAITING);
    ($this->run)($welcome, 'finished@example.com', AutomationRun::STATUS_SUCCESS);
    ($this->run)($stimmanalyse, 'in-unflagged@example.com', AutomationRun::STATUS_WAITING);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'tuesday',
        name: 'Tuesday',
        subject: 'Hello',
        listHandle: 'newsletter',
        content: '<p>Hi</p>',
    ));

    app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('tuesday'));

    expect(Message::forCampaign('tuesday')->pluck('email')->sort()->values()->all())
        ->toBe(['finished@example.com', 'free@example.com', 'in-unflagged@example.com']);

    Mail::assertSent(CampaignMail::class, 3);

    expect(app(CampaignRepository::class)->find('tuesday')->sequencePauseSkipped())->toBe(2);
});

it('sends to the whole list when nothing is flagged', function (): void {
    ($this->run)(($this->automation)('welcome-daily', false), 'in-welcome@example.com', AutomationRun::STATUS_WAITING);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'tuesday',
        name: 'Tuesday',
        subject: 'Hello',
        listHandle: 'newsletter',
        content: '<p>Hi</p>',
    ));

    app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('tuesday'));

    Mail::assertSent(CampaignMail::class, 5);
});
