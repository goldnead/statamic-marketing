<?php

/**
 * The sequence screens without goldnead/statamic-automations installed.
 *
 * This is the default suite, and the sibling is absent here by construction —
 * so what is held is the half of the contract that must work without it: a
 * sequence can be written, its steps are kept in order, the CP says it is not
 * running, and nothing throws. The other half — the automation that gets
 * written — is tests/Integration/SequenceAutomationTest.php.
 */

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Sequence;
use Goldnead\Marketing\Models\SequenceStep;
use Goldnead\Marketing\Sequences\SequenceSync;
use Statamic\Facades\Role;
use Statamic\Facades\User;

beforeEach(function (): void {
    $this->user = User::make()->email('test@example.com')->makeSuper();
    $this->user->save();

    $this->actingAs($this->user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sequencePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Nach dem Kauf',
        'handle' => 'after_purchase',
        'trigger' => 'payments.paid',
        'trigger_config' => [],
        'list' => 'newsletter',
        'enabled' => true,
        'steps' => [
            ['template' => 'danke', 'subject_override' => 'Danke für deinen Kauf', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'tipps', 'subject_override' => 'Drei Tipps für den Start', 'delay_amount' => 3, 'delay_unit' => 'days'],
        ],
    ], $overrides);
}

function sequenceInertiaProps($response): array
{
    return json_decode($response->getContent(), true)['props'] ?? [];
}

it('stores a sequence with its steps in order', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload())
        ->assertRedirect(cp_route('marketing.sequences.edit', 'after_purchase'));

    $sequence = Sequence::query()->where('handle', 'after_purchase')->first();

    expect($sequence)->not->toBeNull()
        ->and($sequence->title)->toBe('Nach dem Kauf')
        ->and($sequence->trigger)->toBe('payments.paid')
        ->and($sequence->list_handle)->toBe('newsletter')
        ->and($sequence->enabled)->toBeTrue()
        ->and($sequence->steps->pluck('position')->all())->toBe([1, 2])
        ->and($sequence->steps->pluck('template')->all())->toBe(['danke', 'tipps'])
        ->and($sequence->steps[1]->delay_amount)->toBe(3)
        ->and($sequence->steps[1]->delay_unit)->toBe('days');
});

it('says the sequence does not run while automations is not installed', function (): void {
    expect(SequenceSync::available())->toBeFalse();

    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    expect($sequence->automation_id)->toBeNull()
        ->and(app(SequenceSync::class)->state($sequence))->toBe(SequenceSync::STATE_UNAVAILABLE);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.sequences.index'));

    $response->assertOk();

    $props = sequenceInertiaProps($response);

    expect($props['automationsAvailable'])->toBeFalse()
        ->and($props['sequences'][0]['state'])->toBe('unavailable')
        ->and($props['sequences'][0]['steps'])->toBe(2);
});

it('renders the graph the automation would be built from', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    $graph = app(SequenceSync::class)->graph($sequence);

    expect(collect($graph['nodes'])->pluck('node_key')->all())->toBe(['trigger', 'mail_1', 'delay_2', 'mail_2'])
        ->and(collect($graph['nodes'])->pluck('type')->all())->toBe(['payments.paid', 'marketing.send_email', 'delay', 'marketing.send_email']);

    $nodes = collect($graph['nodes'])->keyBy('node_key');

    // Once per person, unless the trigger config says otherwise.
    expect($nodes['trigger']['config']['_restart_policy'])->toBe('ignore');

    // Template mode, spelled out: no campaign, the subject on the node, the
    // list the consent comes from, and an empty `to`.
    expect($nodes['mail_1']['config'])->toBe([
        'campaign' => null,
        'template' => 'danke',
        'subject' => 'Danke für deinen Kauf',
        'list' => 'newsletter',
        'to' => null,
        'mail_class' => 'marketing',
    ]);

    expect($nodes['delay_2']['config'])->toBe(['amount' => 3, 'unit' => 'days']);

    expect(collect($graph['edges'])->map(fn ($edge) => $edge['from_node_key'].'>'.$edge['to_node_key'])->all())
        ->toBe(['trigger>mail_1', 'mail_1>delay_2', 'delay_2>mail_2']);
});

it('keeps a trigger config the editor wrote, including its own re-entry rule', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload([
        'trigger_config' => ['product' => 'kurs-a', '_restart_policy' => 'always'],
    ]));

    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();
    $graph = app(SequenceSync::class)->graph($sequence);

    expect($graph['nodes'][0]['config'])->toBe(['product' => 'kurs-a', '_restart_policy' => 'always']);
});

it('refuses a sequence without steps', function (): void {
    $this->from(cp_route('marketing.sequences.create'))
        ->post(cp_route('marketing.sequences.store'), sequencePayload(['steps' => []]))
        ->assertSessionHasErrors('steps');

    expect(Sequence::query()->count())->toBe(0);
});

it('refuses a step without a subject when the template carries none', function (): void {
    $this->from(cp_route('marketing.sequences.create'))
        ->post(cp_route('marketing.sequences.store'), sequencePayload([
            'steps' => [
                ['template' => 'danke', 'subject_override' => '', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ],
        ]))
        ->assertSessionHasErrors('steps.0.subject_override');
});

it('refuses a delay unit the engine does not know', function (): void {
    $this->from(cp_route('marketing.sequences.create'))
        ->post(cp_route('marketing.sequences.store'), sequencePayload([
            'steps' => [
                ['template' => 'danke', 'subject_override' => 'Hi', 'delay_amount' => 2, 'delay_unit' => 'weeks'],
            ],
        ]))
        ->assertSessionHasErrors('steps.0.delay_unit');
});

it('refuses a list that does not exist', function (): void {
    $this->from(cp_route('marketing.sequences.create'))
        ->post(cp_route('marketing.sequences.store'), sequencePayload(['list' => 'nobody']))
        ->assertSessionHasErrors('list');
});

it('keeps the handle unique', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    $this->from(cp_route('marketing.sequences.create'))
        ->post(cp_route('marketing.sequences.store'), sequencePayload(['title' => 'Noch eine']))
        ->assertSessionHasErrors('handle');

    expect(Sequence::query()->count())->toBe(1);
});

it('replaces the steps on update, in the new order', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), sequencePayload([
        'title' => 'Nach dem Kauf, überarbeitet',
        'enabled' => false,
        'steps' => [
            ['template' => 'tipps', 'subject_override' => 'Drei Tipps', 'delay_amount' => 1, 'delay_unit' => 'hours'],
            ['template' => 'danke', 'subject_override' => 'Danke', 'delay_amount' => 0, 'delay_unit' => 'days'],
            ['template' => 'feedback', 'subject_override' => 'Wie läuft es?', 'delay_amount' => 14, 'delay_unit' => 'days'],
        ],
    ]))->assertSessionHasNoErrors();

    $sequence = Sequence::query()->where('handle', 'after_purchase')->firstOrFail();

    expect($sequence->title)->toBe('Nach dem Kauf, überarbeitet')
        ->and($sequence->enabled)->toBeFalse()
        ->and($sequence->steps->pluck('template')->all())->toBe(['tipps', 'danke', 'feedback'])
        ->and($sequence->steps->pluck('position')->all())->toBe([1, 2, 3])
        ->and($sequence->steps[0]->delay_unit)->toBe('hours');
});

it('deletes a sequence and its steps', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    $this->delete(cp_route('marketing.sequences.destroy', 'after_purchase'))
        ->assertRedirect(cp_route('marketing.sequences.index'));

    expect(Sequence::query()->count())->toBe(0)
        ->and(SequenceStep::query()->count())->toBe(0);
});

it('renders the editor for a stored sequence', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.sequences.edit', 'after_purchase'));

    $response->assertOk();

    $props = sequenceInertiaProps($response);

    expect($props['sequence']['steps'])->toHaveCount(2)
        ->and($props['sequence']['state'])->toBe('unavailable')
        ->and($props['triggers'])->toBe([])
        ->and($props['lists'][0]['value'])->toBe('newsletter');
});

it('denies every write to a user who can only view', function (): void {
    $this->post(cp_route('marketing.sequences.store'), sequencePayload());

    Role::make('marketing_reader')->addPermission('view marketing')->save();

    $reader = User::make()->email('reader@example.com');
    $reader->save();
    $reader->assignRole('marketing_reader');
    $reader->save();

    $this->actingAs($reader);

    $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route('marketing.sequences.index'))->assertOk();

    $this->get(cp_route('marketing.sequences.create'))->assertForbidden();
    $this->post(cp_route('marketing.sequences.store'), sequencePayload(['handle' => 'other']))->assertForbidden();
    $this->get(cp_route('marketing.sequences.edit', 'after_purchase'))->assertForbidden();
    $this->patch(cp_route('marketing.sequences.update', 'after_purchase'), sequencePayload())->assertForbidden();
    $this->delete(cp_route('marketing.sequences.destroy', 'after_purchase'))->assertForbidden();

    expect(Sequence::query()->count())->toBe(1);
});

it('denies the sequences screen to a user without the marketing permission', function (): void {
    $nobody = User::make()->email('nobody@example.com');
    $nobody->save();

    $this->actingAs($nobody)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.sequences.index'))
        ->assertForbidden();
});
