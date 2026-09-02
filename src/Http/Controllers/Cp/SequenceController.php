<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Models\Sequence;
use Goldnead\Marketing\Models\SequenceStep;
use Goldnead\Marketing\Sequences\SequenceSync;
use Goldnead\Marketing\Support\EmailTemplateOptions;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Support\Str;

/**
 * Sequences: a mail series as a list, with the automation written for it.
 *
 * Every write here ends in {@see SequenceSync::sync()}. The controller owns
 * the two tables; what runs lives in automations.
 */
class SequenceController extends Controller
{
    public function __construct(
        protected MailingListRepository $lists,
        protected SequenceSync $sync,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view marketing');

        $triggerLabels = collect($this->triggerOptions())->pluck('label', 'value');

        $sequences = Sequence::query()
            ->withCount('steps')
            ->orderBy('title')
            ->get();

        // One query for every automation on the screen, not one per row:
        // `automationFor()` and `state()` would each go to the database again
        // for every sequence listed.
        $automations = $this->sync->automationsFor($sequences);

        $rows = $sequences
            ->map(function (Sequence $sequence) use ($triggerLabels, $automations) {
                $automation = $automations[(int) $sequence->automation_id] ?? null;

                return [
                    'id' => $sequence->handle,
                    'handle' => $sequence->handle,
                    'title' => $sequence->title,
                    'trigger' => $sequence->trigger,
                    'trigger_label' => $triggerLabels->get($sequence->trigger, $sequence->trigger),
                    'list' => $sequence->list_handle,
                    'steps' => (int) $sequence->steps_count,
                    'state' => $this->sync->stateOf($automation),
                    'automation_url' => $this->automationUrl($automation?->id),
                    'last_run_at' => $automation?->last_run_at?->toIso8601String(),
                    'edit_url' => cp_route('marketing.sequences.edit', $sequence->handle),
                    'delete_url' => cp_route('marketing.sequences.destroy', $sequence->handle),
                ];
            })
            ->values()
            ->all();

        $columns = collect([
            Column::make('title')->label(__('marketing::sequences.title_column')),
            Column::make('trigger_label')->label(__('marketing::sequences.trigger')),
            Column::make('list')->label(__('marketing::sequences.list')),
            Column::make('steps')->label(__('marketing::sequences.steps')),
            Column::make('state')->label(__('marketing::sequences.state')),
        ])->map(fn ($c) => $c->toArray())->all();

        return Inertia::render('marketing::Sequences/Index', [
            'sequences' => $rows,
            'columns' => $columns,
            'createUrl' => cp_route('marketing.sequences.create'),
            'canManage' => $this->userCan($request, 'manage marketing sequences'),
            'automationsAvailable' => SequenceSync::available(),
            // Which sentence the screen shows when it is not: automations is
            // missing, or it is there on a storage driver sequences cannot use.
            'unavailableReason' => SequenceSync::unavailableReason(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing sequences');

        return Inertia::render('marketing::Sequences/Edit', $this->editorProps(null) + [
            'storeUrl' => cp_route('marketing.sequences.store'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage marketing sequences');

        $data = $this->validateSequence($request, null);

        $handle = $data['handle'] ?? Str::snake($data['title']);

        if (Sequence::query()->acrossBrands()->where('handle', $handle)->exists()) {
            return back()->withErrors(['handle' => __('marketing::sequences.flashes.handle_taken')]);
        }

        // Sequence, steps and automation in one transaction. Two would leave a
        // sequence without an automation behind when the second one throws —
        // and the handle guard above would then refuse the retry.
        try {
            $sequence = DB::transaction(function () use ($data, $handle): Sequence {
                // The catch sits on this one statement, not on the block: the
                // sync below writes an automation whose handle has its own
                // unique index (`SequenceSync::freeHandle()`), and a 23000
                // from there is not this field's problem. Wrapped wider, a
                // collision over there would have reported "handle taken" at
                // the sequence handle the editor just typed.
                try {
                    $sequence = Sequence::query()->create([
                        'handle' => $handle,
                        'title' => $data['title'],
                        'trigger' => $data['trigger'],
                        'trigger_config' => $data['trigger_config'] ?? [],
                        'list_handle' => $data['list'],
                        'enabled' => (bool) ($data['enabled'] ?? false),
                    ]);
                } catch (QueryException $e) {
                    // The `exists()` check above and this insert are two steps,
                    // and a second request can land between them. The unique
                    // index catches it either way; caught here it becomes a
                    // message at the field instead of a 500. Only the integrity
                    // violation — every other database failure is something
                    // else and travels on to the handler below.
                    if (! $this->isDuplicateHandle($e)) {
                        throw $e;
                    }

                    throw ValidationException::withMessages([
                        'handle' => __('marketing::sequences.flashes.handle_taken'),
                    ]);
                }

                $this->writeSteps($sequence, $data['steps']);

                $this->sync->sync($sequence->load('steps'));

                return $sequence;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->withErrors([
                'automation' => __('marketing::sequences.errors.sync_failed'),
            ]);
        }

        return redirect()
            ->to(cp_route('marketing.sequences.edit', $sequence->handle))
            ->with('success', __('marketing::sequences.flashes.created'));
    }

    public function edit(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing sequences');

        $sequence = Sequence::query()->where('handle', $handle)->first();

        if (! $sequence) {
            abort(404);
        }

        return Inertia::render('marketing::Sequences/Edit', $this->editorProps($sequence) + [
            'updateUrl' => cp_route('marketing.sequences.update', $handle),
            'deleteUrl' => cp_route('marketing.sequences.destroy', $handle),
        ]);
    }

    public function update(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing sequences');

        $sequence = Sequence::query()->where('handle', $handle)->first();

        if (! $sequence) {
            abort(404);
        }

        $data = $this->validateSequence($request, $sequence);

        $confirmedShrink = $request->boolean('confirm_shrink');

        try {
            DB::transaction(function () use ($sequence, $data, $confirmedShrink): void {
                $sequence->fill([
                    'title' => $data['title'],
                    'trigger' => $data['trigger'],
                    'trigger_config' => $data['trigger_config'] ?? [],
                    'list_handle' => $data['list'],
                    'enabled' => (bool) ($data['enabled'] ?? false),
                ])->save();

                $this->writeSteps($sequence, $data['steps']);

                $sequence->load('steps');

                // Asked here, after the new steps and before the graph they
                // produce replaces the old one: the count is the one this save
                // would cause, and refusing rolls the steps back with it.
                $cutOff = $this->sync->runsAsleepOnRemovedSteps($sequence);

                if ($cutOff > 0 && ! $confirmedShrink) {
                    throw ValidationException::withMessages([
                        'confirm_shrink' => trans_choice('marketing::sequences.errors.shrink_warning', $cutOff, ['count' => $cutOff]),
                    ]);
                }

                $this->sync->sync($sequence);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->withErrors([
                'automation' => __('marketing::sequences.errors.sync_failed'),
            ]);
        }

        return back()->with('success', __('marketing::sequences.flashes.updated'));
    }

    public function destroy(Request $request, string $handle)
    {
        $this->authorizeOrFail($request, 'manage marketing sequences');

        $sequence = Sequence::query()->where('handle', $handle)->first();

        if (! $sequence) {
            abort(404);
        }

        // Switched off, not deleted: the automation and its runs are the
        // record of what went to whom. See SequenceSync::disable().
        $this->sync->disable($sequence);

        // The steps explicitly, not by cascade: SQLite only honours the
        // foreign key when the pragma is on, and a sequence that leaves its
        // steps behind is two tables disagreeing about what exists.
        DB::transaction(function () use ($sequence): void {
            $sequence->steps()->delete();
            $sequence->delete();
        });

        return redirect()
            ->to(cp_route('marketing.sequences.index'))
            ->with('success', __('marketing::sequences.flashes.deleted'));
    }

    /**
     * Replace the steps wholesale, in the order they were sent.
     *
     * Rewriting rather than diffing: a step has no identity outside its
     * position — the generated node keys are positional — so "update step 2"
     * and "replace the list" are the same operation, and the second one
     * cannot leave a stale row behind.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    protected function writeSteps(Sequence $sequence, array $steps): void
    {
        $sequence->steps()->delete();

        $position = 0;

        foreach ($steps as $step) {
            $sequence->steps()->create([
                'position' => ++$position,
                'template' => $step['template'],
                'subject_override' => ($step['subject_override'] ?? '') !== '' ? $step['subject_override'] : null,
                'delay_amount' => (int) ($step['delay_amount'] ?? 0),
                'delay_unit' => $step['delay_unit'] ?? 'days',
            ]);
        }
    }

    /**
     * Is this the unique index on `handle` refusing, or a different failure?
     *
     * SQLSTATE 23000 is the integrity-constraint violation MySQL and SQLite
     * report, 23505 the unique violation Postgres reports. Anything else — a
     * full disk, a lost connection, a column too short — is not a taken handle
     * and must not be reported as one.
     */
    protected function isDuplicateHandle(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateSequence(Request $request, ?Sequence $sequence): array
    {
        $triggerHandles = collect($this->triggerOptions())->pluck('value')->all();
        $templateSlugs = EmailTemplateOptions::slugs();
        $templatesInstalled = EmailTemplateOptions::installed();

        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'trigger' => array_filter([
                'required',
                'string',
                'max:100',
                // Only when there is a registry to check against. Without
                // automations the handle is kept as typed, so a sequence
                // written before the addon arrives is not lost.
                $triggerHandles !== [] ? Rule::in($triggerHandles) : null,
            ]),
            'trigger_config' => ['nullable', 'array'],
            'list' => ['required', 'string'],
            'enabled' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.template' => array_filter([
                'required',
                'string',
                'max:255',
                $templatesInstalled && $templateSlugs !== [] ? Rule::in($templateSlugs) : null,
            ]),
            'steps.*.subject_override' => ['nullable', 'string', 'max:255'],
            'steps.*.delay_amount' => ['required', 'integer', 'min:0', 'max:3650'],
            'steps.*.delay_unit' => ['required', Rule::in(SequenceStep::UNITS)],
        ];

        if ($sequence === null) {
            $rules['handle'] = ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'];
        }

        $data = $request->validate($rules, [
            'trigger.in' => __('marketing::sequences.errors.trigger_unknown'),
            'steps.*.template.in' => __('marketing::sequences.errors.template_unknown'),
            'steps.required' => __('marketing::sequences.errors.steps_required'),
            'steps.min' => __('marketing::sequences.errors.steps_required'),
        ]);

        if (! $this->lists->find($data['list'])) {
            throw ValidationException::withMessages(['list' => __('marketing::sequences.errors.list_unknown')]);
        }

        // A template mail goes out under the subject on the node, and the node
        // takes it from here. No override and no subject on the template means
        // a mail with no subject line — refused at save, not discovered in an
        // inbox.
        $errors = [];

        foreach (array_values($data['steps']) as $index => $step) {
            if (trim((string) ($step['subject_override'] ?? '')) !== '') {
                continue;
            }

            if (EmailTemplateOptions::subjectOf((string) $step['template']) === null) {
                $errors["steps.{$index}.subject_override"] = __('marketing::sequences.errors.subject_required');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function editorProps(?Sequence $sequence): array
    {
        $automation = $sequence ? $this->sync->automationFor($sequence) : null;

        return [
            'sequence' => $sequence ? [
                'handle' => $sequence->handle,
                'title' => $sequence->title,
                'trigger' => $sequence->trigger,
                'trigger_config' => $sequence->trigger_config ?? [],
                'list' => $sequence->list_handle,
                'enabled' => (bool) $sequence->enabled,
                'state' => $this->sync->state($sequence),
                'automation_url' => $this->automationUrl($automation?->id),
                'steps' => $sequence->steps->map(fn (SequenceStep $step) => [
                    'template' => $step->template,
                    'subject_override' => $step->subject_override,
                    'delay_amount' => $step->delay_amount,
                    'delay_unit' => $step->delay_unit,
                ])->values()->all(),
            ] : null,
            'triggers' => $this->triggerOptions(),
            'templates' => EmailTemplateOptions::all(),
            'lists' => $this->listOptions(),
            'units' => array_map(fn (string $unit) => [
                'value' => $unit,
                'label' => __('marketing::sequences.units.'.$unit),
            ], SequenceStep::UNITS),
            'automationsAvailable' => SequenceSync::available(),
            // Which sentence the screen shows when it is not: automations is
            // missing, or it is there on a storage driver sequences cannot use.
            'unavailableReason' => SequenceSync::unavailableReason(),
            'emailTemplatesAvailable' => EmailTemplateOptions::installed(),
        ];
    }

    /**
     * The triggers automations knows, with the fields each one takes.
     *
     * Read from the node registry so a trigger a sibling registered yesterday
     * is offered today. Empty without automations: the editor then takes the
     * handle as free text.
     *
     * @return array<int, array{value: string, label: string, group: string, schema: array<int, array<string, mixed>>}>
     */
    protected function triggerOptions(): array
    {
        if (! SequenceSync::available() || ! app()->bound('automations')) {
            return [];
        }

        try {
            $triggers = app('automations')->nodes()->byKind('trigger');
        } catch (\Throwable) {
            return [];
        }

        return collect($triggers)
            ->map(fn (array $trigger) => [
                'value' => (string) $trigger['handle'],
                'label' => (string) ($trigger['label'] ?? $trigger['handle']),
                'group' => (string) ($trigger['group'] ?? ''),
                // The trigger's own fields. The `_`-prefixed ones are the
                // engine's (re-entry, error policy) and the sequence sets the
                // one that matters itself.
                'schema' => collect($trigger['schema'] ?? [])
                    ->filter(fn ($field) => is_array($field)
                        && is_string($field['handle'] ?? null)
                        && ! str_starts_with($field['handle'], '_'))
                    ->map(fn (array $field) => [
                        'handle' => $field['handle'],
                        'label' => (string) ($field['label'] ?? $field['handle']),
                        'type' => (string) ($field['type'] ?? 'text'),
                        'options' => is_array($field['options'] ?? null) ? array_values($field['options']) : [],
                        'required' => (bool) ($field['required'] ?? false),
                        'help' => isset($field['help']) ? (string) $field['help'] : null,
                        'default' => $field['default'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->sortBy([['group', 'asc'], ['label', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The canvas of the managed automation, when the route is there.
     *
     * `cp_route()` prefixes the name with `statamic.cp.`; `Route::has()` does
     * not, so the check has to name the route as it is registered.
     */
    protected function automationUrl(?int $id): ?string
    {
        if ($id === null || ! Route::has('statamic.cp.statamic-automations.automations.edit')) {
            return null;
        }

        return cp_route('statamic-automations.automations.edit', $id);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function listOptions(): array
    {
        return $this->lists->all()
            ->map(fn ($list) => ['value' => $list->handle, 'label' => $list->name])
            ->values()
            ->all();
    }
}
