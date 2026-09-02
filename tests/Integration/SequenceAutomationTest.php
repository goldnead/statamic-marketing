<?php

/**
 * A sequence writes the automation a hand-built series would be — against
 * the real automations tables, with the real node registry.
 *
 * Skips itself when goldnead/statamic-automations is not installed; run
 * scripts/test-siblings.sh (AUTOMATIONS_PATH=../statamic-automations for a
 * local checkout) to exercise it.
 */

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\Actions\SendMarketingEmailAction;
use Goldnead\Marketing\Models\Sequence;
use Goldnead\Marketing\Sequences\SequenceSync;
use Goldnead\StatamicAutomations\Engine\FlowValidator;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;

beforeEach(function (): void {
    if (! class_exists(Automations::class)) {
        $this->markTestSkipped('goldnead/statamic-automations is not installed (run scripts/test-siblings.sh).');
    }

    $this->user = User::make()->email('test@example.com')->makeSuper();
    $this->user->save();

    $this->actingAs($this->user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function managedSequencePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Nach dem Kauf',
        'handle' => 'after_purchase',
        // Registered by automations itself the moment marketing is present,
        // so it exists in this suite without a third addon.
        'trigger' => 'marketing.subscribed',
        'trigger_config' => ['list' => 'newsletter'],
        'list' => 'newsletter',
        'enabled' => false,
        'steps' => [
            ['template' => 'danke', 'subject_override' => 'Danke für deinen Kauf', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'tipps', 'subject_override' => 'Drei Tipps für den Start', 'delay_amount' => 3, 'delay_unit' => 'days'],
        ],
    ], $overrides);
}

function managedAutomation(): Automation
{
    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    expect($sequence->automation_id)->not->toBeNull();

    // Nodes and edges in the order they were written. The relations carry no
    // order of their own, and after a rewrite the engine may hand them back in
    // whatever order the table does.
    return Automation::query()
        ->with(['nodes' => fn ($q) => $q->orderBy('id'), 'edges' => fn ($q) => $q->orderBy('id')])
        ->findOrFail($sequence->automation_id);
}

it('is available once the sibling is installed and migrated', function (): void {
    expect(SequenceSync::available())->toBeTrue();
});

/**
 * The generator names the send node by string, because naming the class would
 * autoload the orchestrator's contract on installs that do not have it. The
 * string is pinned here, where the class can be loaded.
 */
it('names the marketing send node by its real handle', function (): void {
    expect(SequenceSync::SEND_NODE)->toBe(SendMarketingEmailAction::handle());
});

it('writes the automation a hand-built series would be', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload())
        ->assertSessionHasNoErrors();

    $automation = managedAutomation();

    expect($automation->name)->toBe('Nach dem Kauf')
        ->and($automation->handle)->toBe('sequence-after-purchase')
        ->and($automation->enabled)->toBeFalse()
        ->and($automation->created_by)->toBe('marketing.sequence:after_purchase')
        ->and($automation->description)->toContain('Managed by the sequence');

    $nodes = $automation->nodes->keyBy('node_key');

    expect($nodes->keys()->all())->toBe(['trigger', 'mail_1', 'delay_2', 'mail_2'])
        ->and($nodes['trigger']->type)->toBe('marketing.subscribed')
        ->and($nodes['trigger']->config)->toBe(['list' => 'newsletter', '_restart_policy' => 'ignore'])
        ->and($nodes['mail_1']->type)->toBe('marketing.send_email')
        ->and($nodes['mail_1']->config['template'])->toBe('danke')
        ->and($nodes['mail_1']->config['subject'])->toBe('Danke für deinen Kauf')
        ->and($nodes['mail_1']->config['list'])->toBe('newsletter')
        ->and($nodes['mail_1']->config['campaign'])->toBeNull()
        ->and($nodes['delay_2']->type)->toBe('delay')
        ->and($nodes['delay_2']->config)->toBe(['amount' => 3, 'unit' => 'days'])
        ->and($nodes['mail_2']->config['template'])->toBe('tipps');

    expect($automation->edges->map(fn ($edge) => $edge->from_node_key.'>'.$edge->to_node_key)->all())
        ->toBe(['trigger>mail_1', 'mail_1>delay_2', 'delay_2>mail_2']);

    // The engine's own validator agrees this is an automation it can run.
    $errors = collect(app(FlowValidator::class)->validate($automation))
        ->where('level', 'error')
        ->values()
        ->all();

    expect($errors)->toBe([]);
});

it('rewrites the same automation on a second save instead of adding one', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    $first = managedAutomation();

    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
        'title' => 'Nach dem Kauf, überarbeitet',
        'steps' => [
            ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'tipps', 'subject_override' => 'Tipps', 'delay_amount' => 2, 'delay_unit' => 'days'],
            ['template' => 'feedback', 'subject_override' => 'Wie läuft es?', 'delay_amount' => 14, 'delay_unit' => 'days'],
        ],
    ]))->assertSessionHasNoErrors();

    $second = managedAutomation();

    expect(Automation::query()->count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->name)->toBe('Nach dem Kauf, überarbeitet')
        ->and((int) $second->version)->toBe((int) $first->version + 1)
        ->and($second->nodes->pluck('node_key')->all())->toBe(['trigger', 'mail_1', 'delay_2', 'mail_2', 'delay_3', 'mail_3'])
        ->and($second->edges)->toHaveCount(5);
});

it('mirrors the enabled flag onto the automation', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload(['enabled' => true]));

    expect(managedAutomation()->enabled)->toBeTrue();

    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload(['enabled' => false]));

    expect(managedAutomation()->enabled)->toBeFalse();
});

it('reports the state off the automation, which is the thing that runs', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload(['enabled' => true]));

    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    expect(app(SequenceSync::class)->state($sequence))->toBe(SequenceSync::STATE_ENABLED);

    // Switched off on the canvas: the sequence screen has to say so.
    managedAutomation()->forceFill(['enabled' => false])->save();

    expect(app(SequenceSync::class)->state($sequence))->toBe(SequenceSync::STATE_DISABLED);
});

it('switches the automation off when the sequence is deleted, and keeps it', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload(['enabled' => true]));

    $automationId = managedAutomation()->id;

    $this->delete(cp_route('marketing.sequences.destroy', 'after_purchase'));

    $automation = Automation::query()->find($automationId);

    expect(Sequence::query()->count())->toBe(0)
        ->and($automation)->not->toBeNull()
        ->and($automation->enabled)->toBeFalse()
        ->and($automation->nodes()->count())->toBe(4);
});

it('links the editor to the canvas of the automation it wrote', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.sequences.edit', 'after_purchase'));

    $response->assertOk();

    $props = json_decode($response->getContent(), true)['props'];

    // The sibling's CP routes are registered in a real application and not in
    // this harness, so the link is asserted where it can exist and its guard
    // where it cannot — a URL to a route that is not there would be a 404 in
    // the editor, which is the failure the guard prevents.
    $expected = Route::has('statamic.cp.statamic-automations.automations.edit')
        ? cp_route('statamic-automations.automations.edit', managedAutomation()->id)
        : null;

    expect($props['sequence']['state'])->toBe(SequenceSync::STATE_DISABLED)
        ->and($props['sequence']['automation_url'])->toBe($expected);
});

it('offers the registered triggers to the editor', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.sequences.create'));

    $response->assertOk();

    $props = json_decode($response->getContent(), true)['props'];

    expect($props['automationsAvailable'])->toBeTrue()
        ->and(collect($props['triggers'])->pluck('value')->all())->toContain('marketing.subscribed');

    $trigger = collect($props['triggers'])->firstWhere('value', 'marketing.subscribed');

    // The trigger's own fields travel with it; the engine's `_` fields do not.
    expect(collect($trigger['schema'])->pluck('handle')->all())->not->toContain('_restart_policy');
});

it('refuses a trigger the engine does not know', function (): void {
    $this->from(cp_route('marketing.sequences.create'))
        ->post(cp_route('marketing.sequences.store'), managedSequencePayload(['trigger' => 'nothing.ever']))
        ->assertSessionHasErrors('trigger');

    expect(Automation::query()->count())->toBe(0);
});

/**
 * A run asleep in `delay_2`, as the engine would leave it: a waiting run plus
 * the wake-up call that names the node it will resume on.
 */
function sleepingRunOn(string $nodeKey): AutomationRun
{
    $automation = managedAutomation();

    $run = AutomationRun::query()->create([
        'automation_id' => $automation->id,
        'automation_uuid' => $automation->uuid,
        'trigger_node_key' => 'trigger',
        'trigger_type' => $automation->nodes->firstWhere('node_key', 'trigger')->type,
        'subject_key' => 'wartende@example.com',
        'status' => AutomationRun::STATUS_WAITING,
        'context' => [],
        'started_at' => now(),
    ]);

    AutomationScheduledJob::query()->create([
        'automation_id' => $automation->id,
        'automation_run_id' => $run->id,
        'node_key' => $nodeKey,
        'due_at' => now()->addDays(3),
        'status' => AutomationScheduledJob::STATUS_PENDING,
        'payload' => ['output' => [], 'context' => []],
    ]);

    return $run;
}

it('refuses to shorten a sequence people are waiting in, and says how many', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    $first = sleepingRunOn('delay_2');
    $second = sleepingRunOn('delay_2');

    $this->from(cp_route('marketing.sequences.edit', 'after_purchase'))
        ->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
            'steps' => [
                ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ],
        ]))
        ->assertSessionHasErrors('confirm_shrink');

    // Nothing was written: not the steps, not the graph, not the runs.
    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    expect($sequence->steps)->toHaveCount(2)
        ->and(managedAutomation()->nodes->pluck('node_key')->all())->toBe(['trigger', 'mail_1', 'delay_2', 'mail_2'])
        ->and($first->fresh()->status)->toBe(AutomationRun::STATUS_WAITING)
        ->and($second->fresh()->status)->toBe(AutomationRun::STATUS_WAITING)
        ->and(AutomationScheduledJob::query()->where('status', AutomationScheduledJob::STATUS_PENDING)->count())->toBe(2);

    // The number is in the sentence — "some runs" is not a thing anybody can
    // weigh a decision against.
    expect(session('errors')->first('confirm_shrink'))->toContain('2 people');
});

it('says it in the singular when it is one person', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    sleepingRunOn('delay_2');

    $this->from(cp_route('marketing.sequences.edit', 'after_purchase'))
        ->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
            'steps' => [
                ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ],
        ]))
        ->assertSessionHasErrors('confirm_shrink');

    expect(session('errors')->first('confirm_shrink'))->toStartWith('One person is waiting');
});

it('ends the waiting runs when the shortening is confirmed, instead of failing them later', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    $run = sleepingRunOn('delay_2');

    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
        'confirm_shrink' => true,
        'steps' => [
            ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
        ],
    ]))->assertSessionHasNoErrors();

    expect(managedAutomation()->nodes->pluck('node_key')->all())->toBe(['trigger', 'mail_1']);

    // Both halves: the wake-up call cancelled, and the run it would have woken
    // closed with the reason on it. One without the other is a zombie.
    $job = AutomationScheduledJob::query()->where('automation_run_id', $run->id)->firstOrFail();

    expect($job->status)->toBe(AutomationScheduledJob::STATUS_CANCELLED)
        ->and($run->fresh()->status)->toBe(AutomationRun::STATUS_CANCELLED)
        ->and($run->fresh()->error_message)->toContain('after_purchase');
});

it('moves a sleeping run on instead of cancelling it when only the gap is removed', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    $run = sleepingRunOn('delay_2');
    $job = AutomationScheduledJob::query()->where('automation_run_id', $run->id)->firstOrFail();

    // Both steps stay, the wait before the second one goes to zero. `delay_2`
    // disappears from the graph, `mail_2` does not — so nobody is losing a
    // mail and there is nothing to ask about.
    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
        'steps' => [
            ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'tipps', 'subject_override' => 'Tipps', 'delay_amount' => 0, 'delay_unit' => 'days'],
        ],
    ]))->assertSessionHasNoErrors();

    expect(managedAutomation()->nodes->pluck('node_key')->all())->toBe(['trigger', 'mail_1', 'mail_2']);

    // The wake-up call now points in front of mail_2, so resuming after it
    // walks straight into the mail the person was waiting for.
    expect($job->fresh()->node_key)->toBe('mail_1')
        ->and($job->fresh()->status)->toBe(AutomationScheduledJob::STATUS_PENDING)
        ->and($run->fresh()->status)->toBe(AutomationRun::STATUS_WAITING)
        ->and($run->fresh()->error_message)->toBeNull();
});

it('asks about a shortening even when a gap is dropped in the same save', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload([
        'steps' => [
            ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'tipps', 'subject_override' => 'Tipps', 'delay_amount' => 3, 'delay_unit' => 'days'],
            ['template' => 'feedback', 'subject_override' => 'Feedback', 'delay_amount' => 7, 'delay_unit' => 'days'],
        ],
    ]))->assertSessionHasNoErrors();

    $rescued = sleepingRunOn('delay_2');   // gap survives as a step, dropped to zero
    $doomed = sleepingRunOn('delay_3');    // the step itself goes

    $this->from(cp_route('marketing.sequences.edit', 'after_purchase'))
        ->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
            'steps' => [
                ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
                ['template' => 'tipps', 'subject_override' => 'Tipps', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ],
        ]))
        ->assertSessionHasErrors('confirm_shrink');

    // Only the one that really loses its step is counted. The rescued one is
    // not padding in the warning.
    expect(session('errors')->first('confirm_shrink'))->toStartWith('One person is waiting');

    expect($rescued->fresh()->status)->toBe(AutomationRun::STATUS_WAITING)
        ->and($doomed->fresh()->status)->toBe(AutomationRun::STATUS_WAITING);
});

it('leaves a run alone when its node survives the save', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload());

    $run = sleepingRunOn('delay_2');

    // Same number of steps, different content and order: `delay_2` still means
    // the gap before the second mail.
    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), managedSequencePayload([
        'steps' => [
            ['template' => 'tipps', 'subject_override' => 'Tipps zuerst', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'danke', 'subject_override' => 'Danke danach', 'delay_amount' => 5, 'delay_unit' => 'days'],
        ],
    ]))->assertSessionHasNoErrors();

    expect($run->fresh()->status)->toBe(AutomationRun::STATUS_WAITING)
        ->and(AutomationScheduledJob::query()->where('automation_run_id', $run->id)->firstOrFail()->status)
        ->toBe(AutomationScheduledJob::STATUS_PENDING);
});

/**
 * The flat-file driver: automations is installed, but its flows live in files.
 *
 * Everything the sequence guards rest on is an Eloquent read — the automation
 * row behind `automation_id`, and `automation_scheduled_jobs` for the people a
 * save would strand. On this driver `save()` returns a model that was never
 * persisted, so all of it would answer "nothing", and the shrink warning would
 * report zero waiting people forever. Sequences therefore say plainly that
 * they need the database driver instead of half-working.
 */
it('refuses to run on the flat-file driver, and says which one it needs', function (): void {
    config()->set('automations.storage.driver', 'flat_file');
    SequenceSync::forgetAvailability();

    expect(SequenceSync::available())->toBeFalse()
        ->and(SequenceSync::unavailableReason())->toBe(SequenceSync::STATE_UNSUPPORTED_STORAGE);

    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload())
        ->assertSessionHasNoErrors();

    // The sequence is kept, nothing was written into the engine, and the CP
    // does not claim automations is missing — it is installed.
    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    expect($sequence->automation_id)->toBeNull()
        ->and(Automation::query()->count())->toBe(0)
        ->and(app(SequenceSync::class)->state($sequence))->toBe(SequenceSync::STATE_UNSUPPORTED_STORAGE);

    $props = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.sequences.index'))->getContent(),
        true,
    )['props'];

    expect($props['automationsAvailable'])->toBeFalse()
        ->and($props['unavailableReason'])->toBe(SequenceSync::STATE_UNSUPPORTED_STORAGE)
        ->and($props['sequences'][0]['state'])->toBe(SequenceSync::STATE_UNSUPPORTED_STORAGE);
});

/**
 * Every query the listing sends, and the ones that name a table.
 *
 * The whole log, not a filter on `automations`: the first version of this test
 * counted only that table and therefore did not see that reading the state per
 * row still cost a `Schema::hasTable()` round trip each time. A per-row cost
 * has to show up here whichever table it lands on.
 *
 * @return array{total: int, automations: int, props: array<string, mixed>}
 */
function listingQueries(): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = test()->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.sequences.index'));
    $response->assertOk();

    $log = collect(DB::getQueryLog());
    DB::disableQueryLog();

    return [
        'total' => $log->count(),
        'automations' => $log->filter(fn (array $e) => str_contains($e['query'], 'from "automations"'))->count(),
        'props' => json_decode($response->getContent(), true)['props'] ?? [],
    ];
}

it('reads the listing without a single query per row', function (): void {
    $this->post(cp_route('marketing.sequences.store'), managedSequencePayload([
        'handle' => 'erste', 'title' => 'Serie 1',
    ]))->assertSessionHasNoErrors();

    // Warm-up: the first control panel request of a test primes caches that
    // have nothing to do with the number of sequences.
    listingQueries();

    $one = listingQueries();

    foreach (['zweite' => 'Serie 2', 'dritte' => 'Serie 3'] as $handle => $title) {
        $this->post(cp_route('marketing.sequences.store'), managedSequencePayload([
            'handle' => $handle, 'title' => $title,
        ]))->assertSessionHasNoErrors();
    }

    expect(Automation::query()->count())->toBe(3);

    $three = listingQueries();

    // Three rows cost exactly what one row costs. Not "about the same".
    expect($three['total'])->toBe($one['total'])
        ->and($three['automations'])->toBe(1)
        ->and($one['automations'])->toBe(1);

    // And the batch did not cost the answer: every row still reports its own
    // state, read off the automation that was loaded for it.
    expect(collect($three['props']['sequences'])->pluck('state')->all())
        ->toBe([SequenceSync::STATE_DISABLED, SequenceSync::STATE_DISABLED, SequenceSync::STATE_DISABLED]);
});

it('loads the automations of two brands in one query each, not one per sequence', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brands = [
        Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']),
        Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']),
    ];

    $sequences = collect();

    foreach ($brands as $i => $brand) {
        BrandContext::runFor($brand, function () use ($i, $sequences): void {
            // A marketing handle is unique across ALL brands — the public
            // subscribe endpoint derives the brand from it — so each brand
            // needs a list of its own name.
            $list = "newsletter-{$i}";

            app(MailingListRepository::class)->save(new MailingList(handle: $list, name: "Newsletter {$i}"));

            foreach (['eins', 'zwei'] as $n) {
                $this->post(cp_route('marketing.sequences.store'), managedSequencePayload([
                    'handle' => "b{$i}_{$n}",
                    'title' => "Serie {$i} {$n}",
                    'list' => $list,
                    'trigger_config' => ['list' => $list],
                ]))->assertSessionHasNoErrors();
            }

            $sequences->push(...Sequence::query()->get()->all());
        });
    }

    expect($sequences)->toHaveCount(4);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $automations = app(SequenceSync::class)->automationsFor($sequences);

    $queries = collect(DB::getQueryLog())
        ->filter(fn (array $e) => str_contains($e['query'], 'from "automations"'))
        ->count();
    DB::disableQueryLog();

    // Two brands, four sequences: two queries. The loop is per brand, not per
    // row — which is the part nothing held before.
    expect($queries)->toBe(2)
        ->and($automations)->toHaveCount(4);
});
