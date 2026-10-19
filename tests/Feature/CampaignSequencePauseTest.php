<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * "One track at a time": the newsletter leaves out the people who are inside a
 * sequence that pauses it.
 *
 * Marketing does not know what a sequence is. It asks the automations addon
 * (through the bridge) which of this send's recipients are inside a pausing
 * run, and leaves those out. Skipped people simply do not get that issue; there
 * is no catch-up.
 *
 * The addon is absent in this suite, so the bridge is replaced by one that
 * answers from a list. The live answer is tested in tests/Integration against
 * the real addon.
 */
function fakeBridge(?array $inside, bool $throws = false): AutomationsBridge
{
    $manager = new class($inside ?? [], $throws)
    {
        /** @var list<list<string>> */
        public array $asked = [];

        public function __construct(protected array $inside, protected bool $throws) {}

        public function subjectsInPausingRuns(iterable $emails): array
        {
            $emails = collect($emails)->values()->all();
            $this->asked[] = $emails;

            if ($this->throws) {
                throw new RuntimeException('automations is on fire');
            }

            return array_values(array_intersect($emails, $this->inside));
        }
    };

    return new class($inside === null ? null : $manager) extends AutomationsBridge
    {
        public function __construct(public ?object $stub) {}

        protected function manager(): mixed
        {
            return $this->stub;
        }
    };
}

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $list = app(MailingListRepository::class)->find('newsletter');
    $service = app(SubscriptionService::class);

    foreach (['jane', 'john', 'mary'] as $name) {
        $service->subscribe($list, "{$name}@example.com", ['first_name' => ucfirst($name)]);
    }

    $this->campaign = function (array $overrides = []): Campaign {
        app(CampaignRepository::class)->save(new Campaign(...array_merge([
            'handle' => 'tuesday',
            'name' => 'Tuesday issue',
            'subject' => 'Hello',
            'listHandle' => 'newsletter',
            'content' => '<p>Hi {{ first_name }}</p>',
        ], $overrides)));

        return app(CampaignRepository::class)->find('tuesday');
    };

    $this->recipients = fn () => Message::forCampaign('tuesday')->pluck('email')->sort()->values()->all();
});

it('leaves out recipients who are inside a pausing sequence and counts them', function (): void {
    app()->instance(AutomationsBridge::class, fakeBridge(['jane@example.com', 'mary@example.com']));

    app(CampaignSender::class)->queue(($this->campaign)());

    expect(($this->recipients)())->toBe(['john@example.com']);
    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('john@example.com'));

    $campaign = app(CampaignRepository::class)->find('tuesday');

    expect($campaign->sequencePauseSkipped())->toBe(2)
        ->and($campaign->status)->toBe(Campaign::STATUS_SENT);
});

it('logs how many it left out', function (): void {
    Log::spy();
    app()->instance(AutomationsBridge::class, fakeBridge(['jane@example.com']));

    app(CampaignSender::class)->queue(($this->campaign)());

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message) => str_contains($message, '[tuesday]') && str_contains($message, 'left out 1 recipient'))
        ->once();
});

it('sends to everybody when the campaign says so', function (): void {
    $bridge = fakeBridge(['jane@example.com']);
    app()->instance(AutomationsBridge::class, $bridge);

    app(CampaignSender::class)->queue(($this->campaign)(['meta' => ['send_during_sequences' => true]]));

    expect(($this->recipients)())->toBe(['jane@example.com', 'john@example.com', 'mary@example.com'])
        ->and($bridge->stub->asked)->toBe([])
        ->and(app(CampaignRepository::class)->find('tuesday')->sequencePauseSkipped())->toBeNull();
});

it('changes nothing when automations is not installed', function (): void {
    app()->instance(AutomationsBridge::class, fakeBridge(null));

    app(CampaignSender::class)->queue(($this->campaign)());

    expect(($this->recipients)())->toHaveCount(3)
        ->and(app(CampaignRepository::class)->find('tuesday')->sequencePauseSkipped())->toBeNull();
});

it('reports zero rather than nothing when the question was asked and nobody was inside', function (): void {
    app()->instance(AutomationsBridge::class, fakeBridge([]));

    app(CampaignSender::class)->queue(($this->campaign)());

    expect(($this->recipients)())->toHaveCount(3)
        ->and(app(CampaignRepository::class)->find('tuesday')->sequencePauseSkipped())->toBe(0);
});

it('sends anyway, and says so, when automations cannot answer', function (): void {
    Log::spy();
    app()->instance(AutomationsBridge::class, fakeBridge([], throws: true));

    app(CampaignSender::class)->queue(($this->campaign)());

    // The status quo, not a silent stop: a broken check must not hold the
    // newsletter back from the whole list.
    expect(($this->recipients)())->toHaveCount(3)
        ->and(app(CampaignRepository::class)->find('tuesday')->sequencePauseSkipped())->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'inside a sequence'))->atLeast()->once();
});

it('does not hold back a mail that is not a newsletter', function (string $class): void {
    $bridge = fakeBridge(['jane@example.com']);
    app()->instance(AutomationsBridge::class, $bridge);

    app(CampaignSender::class)->queue(($this->campaign)(['mailClass' => $class]));

    expect(($this->recipients)())->toHaveCount(3)
        ->and($bridge->stub->asked)->toBe([]);
})->with(['transactional', 'reminder', 'digest']);

it('counts only people who would otherwise have received the issue', function (): void {
    // mary unsubscribes: she is inside a sequence, but she is not "left out of
    // the issue" because she was never going to get it.
    app(SubscriptionService::class)->unsubscribe(
        Subscription::query()->where('email', 'mary@example.com')->first()
    );
    app()->instance(AutomationsBridge::class, fakeBridge(['jane@example.com', 'mary@example.com']));

    app(CampaignSender::class)->queue(($this->campaign)());

    expect(($this->recipients)())->toBe(['john@example.com'])
        ->and(app(CampaignRepository::class)->find('tuesday')->sequencePauseSkipped())->toBe(1);
});

it('asks about addresses in the normalised form automations stores them in', function (): void {
    $bridge = fakeBridge([]);
    app()->instance(AutomationsBridge::class, $bridge);

    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'Kim.Mixed@Example.COM',
    );

    app(CampaignSender::class)->queue(($this->campaign)());

    expect($bridge->stub->asked[0])->toContain('kim.mixed@example.com')
        ->and($bridge->stub->asked[0])->not->toContain('Kim.Mixed@Example.COM');
});

it('does not touch a test send', function (): void {
    $bridge = fakeBridge(['jane@example.com']);
    app()->instance(AutomationsBridge::class, $bridge);

    app(CampaignSender::class)->sendTest(($this->campaign)(), 'jane@example.com');

    Mail::assertSent(CampaignMail::class, 1);
    expect($bridge->stub->asked)->toBe([]);
});

it('shows the figure on the campaign report', function (): void {
    app()->instance(AutomationsBridge::class, fakeBridge(['jane@example.com']));
    app(CampaignSender::class)->queue(($this->campaign)());

    $this->actingAs(tap(User::make()->email('report@example.com')->makeSuper())->save());

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.campaigns.show', ['tuesday']));

    $response->assertOk();

    expect(json_decode($response->getContent(), true)['props']['stats']['sequence_paused'])->toBe(1);
});

it('shows nothing on the report of a campaign that was never asked', function (): void {
    app()->instance(AutomationsBridge::class, fakeBridge(null));
    app(CampaignSender::class)->queue(($this->campaign)());

    $this->actingAs(tap(User::make()->email('report@example.com')->makeSuper())->save());

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.campaigns.show', ['tuesday']));

    expect(json_decode($response->getContent(), true)['props']['stats']['sequence_paused'])->toBeNull();
});

it('keeps the override when the form saves the campaign and lets it be switched off again', function (): void {
    $this->actingAs(tap(User::make()->email('editor@example.com')->makeSuper())->save());
    $campaign = ($this->campaign)();

    $payload = ['name' => 'Tuesday issue', 'subject' => 'Hello', 'list' => 'newsletter', 'content' => '<p>Hi</p>'];

    $this->patch(cp_route('marketing.campaigns.update', ['tuesday']), $payload + ['send_during_sequences' => true])
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('tuesday')->sendsDuringSequences())->toBeTrue();

    $this->patch(cp_route('marketing.campaigns.update', ['tuesday']), $payload + ['send_during_sequences' => false])
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('tuesday')->sendsDuringSequences())->toBeFalse();
});
