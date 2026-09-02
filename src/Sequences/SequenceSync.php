<?php

namespace Goldnead\Marketing\Sequences;

use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Models\Sequence;
use Goldnead\Marketing\Models\SequenceStep;
use Goldnead\Marketing\Support\EmailTemplateOptions;
use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Engine\VersionManager;
use Goldnead\StatamicAutomations\Models\Automation;
use Goldnead\StatamicAutomations\Models\AutomationRun;
use Goldnead\StatamicAutomations\Models\AutomationScheduledJob;
use Illuminate\Support\Facades\DB;

/**
 * Writes a sequence into the automations engine, and keeps it there.
 *
 * **A sequence is a view plus a generator, not a second scheduler.** Saving
 * one produces exactly the automation an editor would otherwise build by hand
 * on the canvas: the trigger, then for every step a `delay` (when the step
 * has a gap) and a `marketing.send_email` node in template mode, wired in one
 * straight line. What waits between the mails is `automation_scheduled_jobs`;
 * what sends is the marketing send path with consent, suppression, opt-out
 * and the frequency cap — the same two things a hand-built series uses. This
 * class adds no queue and no timer of its own.
 *
 * **Node keys are positional.** `trigger`, `mail_1`, `delay_2`, `mail_2`, …
 * — the number is the step's position, and a step without a gap contributes
 * no delay node at all. A second save rewrites the graph through
 * {@see AutomationRepository::save()}, which replaces every node row, so the
 * keys are what decides whether a sleeping run survives it:
 *
 *  - **Same number of steps or more:** every key a paused run may be waiting
 *    on still exists, and the run resumes on a node that still means the same
 *    place in the series. Changing a template, a subject or a delay is
 *    therefore a change of content for a run already under way, not a lost
 *    run. Reordering is the same thing: positions keep their meaning, the
 *    mails behind them change.
 *  - **A gap taken out of a step:** setting a step's wait to zero drops its
 *    `delay_N` while `mail_N` stays. Nobody loses a mail — they lose a wait,
 *    which is what was asked for. {@see self::settleStrandedRuns()} moves
 *    their wake-up call in front of `mail_N`, so they get it on the next run
 *    of the scheduler. Nothing is cancelled and nothing is asked.
 *  - **Fewer steps:** the keys past the new end are gone, and a run asleep on
 *    one of them has nothing left to wake up on. Left alone, the engine would
 *    find out days later: `ResumeDelayedRun` fires,
 *    `WorkflowRunner::resumeAfterNode()` cannot find the node, and the run
 *    ends as failed with a line in a log nobody on the marketing side reads.
 *    That is a silent stop for the people in the series, so this class does
 *    not leave it alone. Two things happen instead:
 *
 *      1. {@see self::runsAsleepOnRemovedSteps()} counts them **before** the
 *         graph is replaced. The controller refuses the save and names the
 *         number; the editor confirms, or takes the steps back.
 *      2. On a confirmed save, {@see self::settleStrandedRuns()} cancels the
 *         wake-up calls and closes those runs as `cancelled` with the reason
 *         written on them — at the moment of the decision, not three days
 *         later under a different heading.
 *
 *    Shortening a running series stays a decision. It is now a decision
 *    somebody made on purpose.
 *
 * **Only on the `database` storage driver.** See
 * {@see self::determineUnavailableReason()}: with `flat_file` there is no
 * automation row to point at and no way to ask which runs are waiting, so
 * every guard above would read zero. The CP says so rather than half-working.
 *
 * **Marked, not locked.** The automation carries `created_by =
 * marketing.sequence:<handle>` and a description that says who owns it. The
 * automations addon has no read-only flag for a flow, so the canvas can still
 * edit it; the next save of the sequence overwrites whatever was done there.
 * The description says so on the canvas, and the sequence editor says so on
 * this side.
 *
 * **Without automations, nothing here runs.** {@see self::available()} is
 * false, `sync()` returns null, the sequence is stored and the CP says it is
 * not running. Installing the addon later and saving the sequence once is
 * enough.
 */
class SequenceSync
{
    /** Horizontal distance between nodes on the canvas. */
    public const SPACING = 250;

    /** Automations is not installed (or not migrated). */
    public const STATE_UNAVAILABLE = 'unavailable';

    /** Automations is installed, but storing its definitions as flat files. */
    public const STATE_UNSUPPORTED_STORAGE = 'unsupported_storage';

    public const STATE_DETACHED = 'detached';

    public const STATE_ENABLED = 'enabled';

    public const STATE_DISABLED = 'disabled';

    /** The one automations storage driver a sequence can be written into. */
    public const SUPPORTED_STORAGE = 'database';

    /**
     * Memoised {@see self::unavailableReason()}: `null` while unasked, `false`
     * once the answer is "it works", else the reason.
     */
    protected static string|false|null $unavailableReason = null;

    /**
     * A trigger node's re-entry key and the value a series wants. Spelled out
     * rather than imported from the orchestrator's `RestartPolicy`, so
     * {@see self::graph()} stays callable — and testable — on an install
     * without automations.
     */
    protected const REENTRY_KEY = '_restart_policy';

    protected const REENTRY_ONCE = 'ignore';

    protected const TRIGGER_KEY = 'trigger';

    /**
     * The marketing send node, by handle rather than by class. The class
     * implements an automations contract, so merely naming it autoloads the
     * sibling — and {@see self::graph()} has to run without it. The
     * integration suite pins this string to `SendMarketingEmailAction::handle()`.
     */
    public const SEND_NODE = 'marketing.send_email';

    /**
     * Is there an engine to write into?
     */
    public static function available(): bool
    {
        return static::unavailableReason() === null;
    }

    /**
     * Why a sequence cannot be written here, or null when it can.
     *
     * One of {@see self::STATE_UNAVAILABLE} or
     * {@see self::STATE_UNSUPPORTED_STORAGE} — the CP shows a different
     * sentence for each, because "automations is not installed" is a lie when
     * it is installed and merely keeping its flows in files.
     *
     * **Memoised for the life of the process.** The check behind it ends in
     * `Automation::schemaReady()`, which is a `Schema::hasTable()`, and
     * Laravel sends that to the database on every call rather than caching it.
     * Unmemoised, a listing that reads the state of fifty sequences paid for
     * fifty round trips — the N+1 this class had just removed, moved one table
     * over. Neither answer can change inside a request; a long-running worker
     * (Octane) picks up a change on the restart a deploy does anyway, and the
     * test suite calls {@see self::forgetAvailability()} between cases.
     */
    public static function unavailableReason(): ?string
    {
        if (static::$unavailableReason === null) {
            static::$unavailableReason = static::determineUnavailableReason() ?? false;
        }

        return static::$unavailableReason ?: null;
    }

    /**
     * Drop the memo. For tests, and for anything that changes the config or
     * runs migrations inside one process.
     */
    public static function forgetAvailability(): void
    {
        static::$unavailableReason = null;
    }

    protected static function determineUnavailableReason(): ?string
    {
        if (! AutomationsBridge::available() || ! class_exists(Automation::class) || ! Automation::schemaReady()) {
            return self::STATE_UNAVAILABLE;
        }

        // Sequences read the engine through Eloquent — the automation row for
        // the state badge, `automation_scheduled_jobs` for the runs a save
        // would strand. With the `flat_file` driver there is no row:
        // `FlatFileAutomationRepository::save()` hands back a model that was
        // never persisted, so `automation_id` would stay null, the badge would
        // read "not linked" forever, and the shrink guard would count zero
        // waiting people every single time. That last one is the failure this
        // class exists to prevent, silently reintroduced.
        //
        // Reading through `AutomationRepository` instead would fix the first
        // two and not the third: the contract has no way to ask which runs are
        // waiting on a flow. So sequences say plainly that they need the
        // `database` driver, rather than half-working.
        if (static::storageDriver() !== self::SUPPORTED_STORAGE) {
            return self::STATE_UNSUPPORTED_STORAGE;
        }

        return null;
    }

    protected static function storageDriver(): string
    {
        return (string) config('automations.storage.driver', self::SUPPORTED_STORAGE);
    }

    /**
     * Create or rewrite the automation this sequence manages.
     *
     * Null when automations is not installed — the sequence is saved either
     * way, and the caller shows that it is not running.
     */
    public function sync(Sequence $sequence): ?Automation
    {
        if (! static::available()) {
            return null;
        }

        $sequence->loadMissing('steps');

        return $this->inBrandOf($sequence, fn () => DB::transaction(function () use ($sequence): Automation {
            $automation = $this->automationFor($sequence);

            if ($automation === null) {
                $automation = new Automation(['handle' => $this->freeHandle($sequence)]);
            } else {
                // Like every other write path in automations: the graph as it
                // was is kept, so a rewrite can be rolled back from the same
                // history a canvas edit would be.
                if (class_exists(VersionManager::class)) {
                    app(VersionManager::class)->snapshot($automation, 'Rewritten by sequence '.$sequence->handle);
                }

                $automation->version = (int) $automation->version + 1;
            }

            $automation->fill([
                'name' => $sequence->title,
                'description' => $this->description($sequence),
                'enabled' => (bool) $sequence->enabled,
                'created_by' => $sequence->managedBy(),
            ]);

            $graph = $this->graph($sequence);

            $automation = app(AutomationRepository::class)->save($automation, $graph['nodes'], $graph['edges']);

            // The graph is the new one now, so anything still scheduled on a
            // key it no longer has needs handling: moved on where the step it
            // was waiting for survives, ended where it does not. Inside the
            // same transaction that removed the node.
            $this->settleStrandedRuns($sequence, $automation, $graph);

            if ((int) $sequence->automation_id !== (int) $automation->id) {
                $sequence->forceFill(['automation_id' => $automation->id])->saveQuietly();
            }

            return $automation;
        }));
    }

    /**
     * How many waiting runs this sequence's current steps would cut off.
     *
     * Asked between the steps being rewritten and the graph being replaced,
     * so the number is the one an editor is about to cause. Zero for a
     * sequence that has no automation yet, and zero without automations.
     *
     * `automation_scheduled_jobs` is the source rather than `automation_runs`:
     * the wake-up call is the only row that writes down *which* node the run
     * will resume on, and it is the row that would fire.
     */
    public function runsAsleepOnRemovedSteps(Sequence $sequence): int
    {
        if (! static::available() || ! $sequence->automation_id) {
            return 0;
        }

        $graph = $this->graph($sequence);

        return (int) $this->inBrandOf(
            $sequence,
            fn (): int => count($this->classifyStranded((int) $sequence->automation_id, $graph)['cancel']),
        );
    }

    /**
     * Deal with the wake-up calls this rewrite has stranded.
     *
     * Two outcomes, because there are two ways to strand one:
     *
     *  - **The step survived, only its gap was removed.** Taking a step's wait
     *    down to zero deletes `delay_N` while `mail_N` stays. The person is
     *    not losing a mail, they are losing a wait — which is exactly what the
     *    editor asked for. Their wake-up call is moved to the node in front of
     *    `mail_N`, so they get that mail on the next run of the scheduler
     *    instead of being cancelled. This is the same contract a shortened gap
     *    has always had: a changed wait changes what a sleeping run gets next.
     *  - **The step itself is gone.** Nothing to move them on to. The call is
     *    cancelled and the run closed, with the reason on it.
     *
     * The cancelling half is modelled on `EnrollmentGate::cancelOpenRuns()` in
     * automations, for the same reason it exists there: cancelling a run
     * without its wake-up call leaves the call to fire anyway, and cancelling
     * the call without closing the run leaves a run that says `waiting`
     * forever with nothing left to wake it. Both, or neither.
     *
     * **This reaches straight into two of the sibling's tables, and that is a
     * placeholder, not a design.** Automations exposes no public way to ask
     * "which runs are waiting on this flow" or to move one along;
     * `EnrollmentGate::cancelOpenRuns()` is `protected` and carries an
     * enrolment signature that does not fit here. A ticket for that interface
     * is filed on the automations side; when it lands, this method is what
     * calls it.
     *
     * **Known gap:** only a run that is *asleep* is seen. A run being walked
     * right now — its scheduled job already `dispatched`, the run `running` —
     * has no pending wake-up call to find, so it is neither counted in the
     * warning nor settled here. It hits the removed node inside `walk()` and
     * fails there. The window is the length of one run, and closing it needs
     * the same interface as above.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph  the graph as it is *after* this save
     * @return array{moved: int, ended: int}
     */
    protected function settleStrandedRuns(Sequence $sequence, Automation $automation, array $graph): array
    {
        $stranded = $this->classifyStranded((int) $automation->id, $graph);

        foreach ($stranded['repoint'] as $jobId => $nodeKey) {
            AutomationScheduledJob::query()->whereKey($jobId)->update(['node_key' => $nodeKey]);
        }

        $cancel = $stranded['cancel'];

        if ($cancel !== []) {
            AutomationScheduledJob::query()
                ->whereIn('id', array_keys($cancel))
                ->update(['status' => AutomationScheduledJob::STATUS_CANCELLED]);

            $runIds = array_values(array_unique(array_filter($cancel)));

            if ($runIds !== []) {
                AutomationRun::query()
                    ->whereIn('id', $runIds)
                    ->whereIn('status', [
                        AutomationRun::STATUS_WAITING,
                        AutomationRun::STATUS_QUEUED,
                        AutomationRun::STATUS_RUNNING,
                    ])
                    ->update([
                        'status' => AutomationRun::STATUS_CANCELLED,
                        'error_message' => sprintf(
                            'Cancelled: the sequence "%s" was shortened and the step this run was waiting for no longer exists.',
                            $sequence->handle,
                        ),
                        'finished_at' => now(),
                    ]);
            }
        }

        return ['moved' => count($stranded['repoint']), 'ended' => count($cancel)];
    }

    /**
     * Split the stranded wake-up calls into the ones that can be moved on and
     * the ones that cannot.
     *
     * Callers run this inside the automation's own brand.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return array{repoint: array<int, string>, cancel: array<int, int|null>} job id → new node key / job id → run id
     */
    protected function classifyStranded(int $automationId, array $graph): array
    {
        $keys = array_column($graph['nodes'], 'node_key');

        // What now feeds each node. `delay_2` gone means `mail_2` is fed by
        // whatever came before the gap, and resuming *after* that node walks
        // straight into `mail_2`.
        $feeds = [];

        foreach ($graph['edges'] as $edge) {
            $feeds[$edge['to_node_key']] = $edge['from_node_key'];
        }

        $jobs = AutomationScheduledJob::query()
            ->where('automation_id', $automationId)
            ->whereIn('status', [AutomationScheduledJob::STATUS_PENDING, AutomationScheduledJob::STATUS_QUEUED])
            ->whereNotIn('node_key', $keys)
            ->get(['id', 'automation_run_id', 'node_key']);

        $repoint = [];
        $cancel = [];

        foreach ($jobs as $job) {
            $step = preg_match('/^delay_(\d+)$/', (string) $job->node_key, $m) ? 'mail_'.$m[1] : null;

            if ($step !== null && isset($feeds[$step])) {
                $repoint[(int) $job->id] = $feeds[$step];

                continue;
            }

            $cancel[(int) $job->id] = $job->automation_run_id === null ? null : (int) $job->automation_run_id;
        }

        return ['repoint' => $repoint, 'cancel' => $cancel];
    }

    /**
     * Switch the managed automation off without deleting it.
     *
     * Called when the sequence is deleted. The automation stays, disabled: it
     * is the record of what was sent to whom, and its runs are somebody's
     * history. An editor who wants it gone deletes it in Automations.
     */
    public function disable(Sequence $sequence): void
    {
        $automation = $this->automationFor($sequence);

        if ($automation === null) {
            return;
        }

        $automation->forceFill(['enabled' => false])->save();
    }

    public function automationFor(Sequence $sequence): ?Automation
    {
        if (! static::available() || ! $sequence->automation_id) {
            return null;
        }

        return $this->inBrandOf($sequence, fn () => Automation::query()->find($sequence->automation_id));
    }

    /**
     * The managed automations of many sequences at once, keyed by their id.
     *
     * For the listing, which needs one automation per row and would otherwise
     * ask for each row separately. Grouped by brand because the brand is the
     * only thing the scope cares about, and a listing is one brand — so this
     * is one query for the whole screen.
     *
     * @param  iterable<Sequence>  $sequences
     * @return array<int, Automation>
     */
    public function automationsFor(iterable $sequences): array
    {
        if (! static::available()) {
            return [];
        }

        $idsByBrand = [];

        foreach ($sequences as $sequence) {
            if ($sequence->automation_id) {
                $idsByBrand[(int) $sequence->brand_id][] = (int) $sequence->automation_id;
            }
        }

        $found = [];

        foreach ($idsByBrand as $brandId => $ids) {
            $automations = $this->inBrand(
                $brandId ?: null,
                fn () => Automation::query()->whereIn('id', array_unique($ids))->get(),
            );

            foreach ($automations as $automation) {
                $found[(int) $automation->id] = $automation;
            }
        }

        return $found;
    }

    /**
     * Run a closure as the sequence's own brand.
     *
     * The automation, its nodes and its edges are brand-scoped, and the scope
     * fails closed: outside a request — a console command, a queue job, a
     * seed — there is no current brand and every query answers nothing. The
     * sequence row knows which brand it belongs to, so that is the brand
     * everything it writes is written as, wherever the call comes from.
     */
    protected function inBrandOf(Sequence $sequence, \Closure $callback): mixed
    {
        return $this->inBrand($sequence->brand_id ? (int) $sequence->brand_id : null, $callback);
    }

    /**
     * The same, for a brand id on its own — the batch paths have sequences
     * grouped by brand rather than one sequence to ask.
     */
    protected function inBrand(?int $brandId, \Closure $callback): mixed
    {
        if (! $brandId || ! app()->bound('brand-context')) {
            return $callback();
        }

        return app('brand-context')->runFor($brandId, $callback);
    }

    /**
     * What the CP says next to the sequence.
     *
     * Read off the automation, not off the sequence row: the automation is
     * the thing that runs, and if somebody switched it off on the canvas the
     * sequence screen has to say so rather than repeat its own flag.
     */
    public function state(Sequence $sequence): string
    {
        if (($reason = static::unavailableReason()) !== null) {
            return $reason;
        }

        return $this->stateOf($this->automationFor($sequence));
    }

    /**
     * The same answer for an automation already in hand.
     *
     * The listing resolves its automations in one query
     * ({@see self::automationsFor()}) and asks here, so reading the state of
     * fifty sequences costs no queries at all.
     */
    public function stateOf(?Automation $automation): string
    {
        if (($reason = static::unavailableReason()) !== null) {
            return $reason;
        }

        if ($automation === null) {
            return self::STATE_DETACHED;
        }

        return $automation->enabled ? self::STATE_ENABLED : self::STATE_DISABLED;
    }

    /**
     * The graph a sequence stands for: nodes and edges in the array shape
     * {@see AutomationRepository::save()} takes.
     *
     * Pure. Reads the sequence and its steps, touches no table, and needs no
     * sibling installed — which is what the tests without automations hold
     * it to.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function graph(Sequence $sequence): array
    {
        $sequence->loadMissing('steps');

        $triggerConfig = is_array($sequence->trigger_config) ? $sequence->trigger_config : [];

        // A series is something a person goes through once. The default
        // policy enrolls again every time the trigger fires, which for a
        // welcome series means being welcomed twice; a trigger config that
        // names its own policy keeps it.
        $triggerConfig += [self::REENTRY_KEY => self::REENTRY_ONCE];

        $nodes = [[
            'node_key' => self::TRIGGER_KEY,
            'type' => $sequence->trigger,
            'label' => null,
            'position_x' => 0,
            'position_y' => 0,
            'config' => $triggerConfig,
        ]];

        $edges = [];
        $previous = self::TRIGGER_KEY;
        $x = 0;

        $position = 0;

        foreach ($sequence->steps as $step) {
            /** @var SequenceStep $step */
            $position++;

            if ($step->hasDelay()) {
                $x += self::SPACING;
                $key = 'delay_'.$position;

                $nodes[] = [
                    'node_key' => $key,
                    'type' => 'delay',
                    'label' => null,
                    'position_x' => $x,
                    'position_y' => 0,
                    'config' => [
                        'amount' => (int) $step->delay_amount,
                        'unit' => in_array($step->delay_unit, SequenceStep::UNITS, true) ? $step->delay_unit : 'days',
                    ],
                ];

                $edges[] = ['from_node_key' => $previous, 'from_output' => 'default', 'to_node_key' => $key, 'to_input' => 'default'];
                $previous = $key;
            }

            $x += self::SPACING;
            $key = 'mail_'.$position;
            $subject = $this->subjectFor($step);

            $nodes[] = [
                'node_key' => $key,
                'type' => self::SEND_NODE,
                'label' => $subject !== '' ? $subject : $step->template,
                'position_x' => $x,
                'position_y' => 0,
                // Template mode, spelled out field by field: the campaign
                // stays empty (this is not a campaign), the subject is the one
                // the recipient sees, the list is where consent comes from,
                // and an empty `to` is the address the run is already about.
                'config' => [
                    'campaign' => null,
                    'template' => $step->template,
                    'subject' => $subject,
                    'list' => $sequence->list_handle,
                    'to' => null,
                    'mail_class' => 'marketing',
                ],
            ];

            $edges[] = ['from_node_key' => $previous, 'from_output' => 'default', 'to_node_key' => $key, 'to_input' => 'default'];
            $previous = $key;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The subject a step sends under.
     *
     * The override when there is one, else the subject the template entry
     * carries — read at save time and written into the node, because
     * `marketing.send_email` in template mode reads its subject from the node
     * and nothing else. A changed template subject reaches the automation on
     * the next save of the sequence; the sequence editor says so.
     */
    public function subjectFor(SequenceStep $step): string
    {
        $override = trim((string) $step->subject_override);

        if ($override !== '') {
            return $override;
        }

        return (string) (EmailTemplateOptions::subjectOf($step->template) ?? '');
    }

    /**
     * What the canvas shows under the automation's name.
     */
    public function description(Sequence $sequence): string
    {
        return sprintf(
            'Managed by the sequence "%s" (Marketing → Sequences). Edits made here are overwritten the next time the sequence is saved.',
            $sequence->title,
        );
    }

    /**
     * An automation handle nobody else in this brand holds.
     *
     * Named after the sequence, so the two can be matched by eye in a list.
     * A collision with a hand-built automation is possible and gets a suffix
     * rather than an exception — the sequence is the thing being saved, and
     * an editor cannot do anything about a name on the other screen.
     */
    protected function freeHandle(Sequence $sequence): string
    {
        $base = 'sequence-'.str_replace('_', '-', $sequence->handle);
        $candidate = $base;
        $suffix = 2;

        while (Automation::query()->where('handle', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
