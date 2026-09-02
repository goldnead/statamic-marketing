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
use Illuminate\Database\Eloquent\Builder;
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
 *  - **Fewer steps, or a gap taken out of one:** the keys that are gone are
 *    gone, and a run asleep on one of them has nothing left to wake up on.
 *    Left alone, the engine would find out days later: `ResumeDelayedRun`
 *    fires, `WorkflowRunner::resumeAfterNode()` cannot find the node, and the
 *    run ends as failed with a line in a log nobody on the marketing side
 *    reads. That is a silent stop for the people in the series, so this class
 *    does not leave it alone. Two things happen instead:
 *
 *      1. {@see self::runsAsleepOnRemovedSteps()} counts them **before** the
 *         graph is replaced. The controller refuses the save and names the
 *         number; the editor confirms, or takes the steps back.
 *      2. On a confirmed save, {@see self::endOrphanedRuns()} cancels the
 *         wake-up calls and closes those runs as `cancelled` with the reason
 *         written on them — at the moment of the decision, not three days
 *         later under a different heading.
 *
 *    Shortening a running series stays a decision. It is now a decision
 *    somebody made on purpose.
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

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_DETACHED = 'detached';

    public const STATE_ENABLED = 'enabled';

    public const STATE_DISABLED = 'disabled';

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
        return AutomationsBridge::available()
            && class_exists(Automation::class)
            && Automation::schemaReady();
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
            // key it no longer has is a run with nowhere to go. Ended here,
            // inside the same transaction that removed its node.
            $this->endOrphanedRuns($sequence, $automation, array_column($graph['nodes'], 'node_key'));

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

        $keys = array_column($this->graph($sequence)['nodes'], 'node_key');

        return (int) $this->inBrandOf($sequence, fn (): int => $this->pendingJobsOutside(
            (int) $sequence->automation_id,
            $keys,
        )->count());
    }

    /**
     * End the runs whose next node this rewrite has just removed.
     *
     * Modelled on `EnrollmentGate::cancelOpenRuns()` in automations, and for
     * the same reason: cancelling a run without cancelling its wake-up call
     * leaves the call to fire anyway, and cancelling the call without closing
     * the run leaves a run that says `waiting` forever with nothing left to
     * wake it. Both, or neither.
     *
     * @param  list<string>  $nodeKeys  the keys the automation has *after* this save
     * @return int runs ended
     */
    protected function endOrphanedRuns(Sequence $sequence, Automation $automation, array $nodeKeys): int
    {
        $jobs = $this->pendingJobsOutside((int) $automation->id, $nodeKeys)->get(['id', 'automation_run_id']);

        if ($jobs->isEmpty()) {
            return 0;
        }

        AutomationScheduledJob::query()
            ->whereIn('id', $jobs->pluck('id')->all())
            ->update(['status' => AutomationScheduledJob::STATUS_CANCELLED]);

        $runIds = $jobs->pluck('automation_run_id')->filter()->unique()->values()->all();

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

        return $jobs->count();
    }

    /**
     * Wake-up calls of this automation that point at a node key not in $keys.
     *
     * Callers run this inside the automation's own brand; the builder is not
     * handed out beyond this class for that reason.
     *
     * @param  list<string>  $keys
     * @return Builder<AutomationScheduledJob>
     */
    protected function pendingJobsOutside(int $automationId, array $keys)
    {
        return AutomationScheduledJob::query()
            ->where('automation_id', $automationId)
            ->whereIn('status', [AutomationScheduledJob::STATUS_PENDING, AutomationScheduledJob::STATUS_QUEUED])
            ->whereNotIn('node_key', $keys);
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
        if (! static::available()) {
            return self::STATE_UNAVAILABLE;
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
        if (! static::available()) {
            return self::STATE_UNAVAILABLE;
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
