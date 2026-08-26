<?php

namespace Goldnead\Marketing\Console;

use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Hands back messages whose worker never came home.
 *
 * The claim in `SendMessageJob` is what stops one mail going out twice. It also
 * creates the opposite risk: a worker killed *after* claiming and *before*
 * resolving leaves the row in `sending` with nothing left to pick it up. That
 * trades a duplicate for a disappearance, which is the worse of the two — a
 * duplicate gets complained about, a missing newsletter does not.
 *
 * So the claim is a lease, and this is what enforces it. Anything held longer
 * than `marketing.sending.claim_lease_minutes` goes back to `pending` and is
 * dispatched again.
 *
 * WHY THE LEASE IS LONG BY DEFAULT
 * --------------------------------
 * The only thing standing between a released message and a duplicate is the
 * assumption that the first worker is really gone. Release too eagerly and this
 * command becomes the very bug it was written for: the original worker is still
 * rendering a large campaign, the message is handed to a second one, and both
 * send. Fifteen minutes is far longer than any single send takes and far
 * shorter than a campaign is willing to stall.
 *
 * It reports what it did rather than doing it quietly. A message that needs
 * releasing means a worker died, and that is worth knowing even when this
 * command has already cleaned up after it.
 */
class ReleaseStaleSendsCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'marketing:release-stale-sends
        {--brand= : Restrict the run to one brand}
        {--dry-run : Report what would be released, release nothing}';

    protected $description = 'Gibt Nachrichten zurück, deren Arbeiter nicht zurückkam';

    public function handle(): int
    {
        return $this->forEachBrand(fn () => $this->release());
    }

    protected function release(): int
    {
        $lease = max(1, (int) config('marketing.sending.claim_lease_minutes', 15));
        $cutoff = now()->subMinutes($lease);

        $stuck = Message::query()
            ->where('status', Message::STATUS_SENDING)
            // A claimed row always carries the timestamp — but a row that
            // somehow holds the status without one is stuck just as hard, and
            // leaving it out would make this command quietly incomplete.
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff))
            ->get(['id', 'campaign_handle', 'email', 'claimed_at', 'sent_at']);

        // The split that keeps this command from becoming the bug it cleans up
        // after. `sent_at` is stamped immediately before the handover to the
        // transport, so a stuck row that carries it was already given to the
        // mail server — the worker died afterwards. Re-queueing that one sends
        // a second copy to a real person.
        //
        // Found by review, reproduced: without this split the sweeper turned
        // one delivered message into two mails.
        $handedOver = $stuck->filter(fn ($m): bool => $m->sent_at !== null);
        $stale = $stuck->reject(fn ($m): bool => $m->sent_at !== null);

        if ($stuck->isEmpty()) {
            $this->line('Nichts hängt fest.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['id', 'Kampagne', 'E-Mail', 'beansprucht', 'was passiert'], $stuck->map(fn ($m): array => [
                $m->id, $m->campaign_handle, $m->email,
                $m->claimed_at?->toDateTimeString() ?? '—',
                $m->sent_at !== null ? 'abgeschlossen (war schon übergeben)' : 'erneut zugestellt',
            ])->all());

            return self::SUCCESS;
        }

        $this->closeHandedOver($handedOver);

        foreach ($stale as $message) {
            // Released one at a time, and re-dispatched only if the release
            // actually took. A bulk UPDATE followed by a bulk dispatch would
            // re-queue rows another worker had just resolved.
            if (Message::query()->whereKey($message->id)->where('status', Message::STATUS_SENDING)
                ->update(['status' => Message::STATUS_PENDING, 'claimed_at' => null]) !== 1) {
                continue;
            }

            SendMessageJob::dispatch($message->id)
                ->onQueue(config('marketing.sending.queue', 'default'));
        }

        if ($stale->isNotEmpty()) {
            Log::warning('marketing: released stale send claims', [
                'count' => $stale->count(),
                'lease_minutes' => $lease,
                'message_ids' => $stale->pluck('id')->all(),
            ]);

            $this->warn(sprintf(
                '%d Nachricht(en) erneut zugestellt — so viele Arbeiter sind vor der Übergabe gestorben.',
                $stale->count()
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Finish rows that were already handed to the transport.
     *
     * Deliberately NOT re-queued, and deliberately loud. Whether the mail
     * actually arrived is not knowable from here: the worker was killed inside
     * the handover. Sending again would guarantee a duplicate to a real
     * subscriber; closing it as sent risks one person missing one mail.
     *
     * The second is the smaller harm and the one that can be looked into,
     * which is why the log names the rows instead of counting them. Saying
     * nothing and picking either answer is what would make this the quiet kind
     * of bug.
     *
     * @param  Collection<int, Message>  $handedOver
     */
    protected function closeHandedOver($handedOver): void
    {
        if ($handedOver->isEmpty()) {
            return;
        }

        Message::query()
            ->whereIn('id', $handedOver->pluck('id')->all())
            ->where('status', Message::STATUS_SENDING)
            ->update(['status' => Message::STATUS_SENT]);

        Log::warning('marketing: closed messages that were already handed to the transport', [
            'count' => $handedOver->count(),
            'message_ids' => $handedOver->pluck('id')->all(),
            'note' => 'Worker died inside the handover. Not re-sent: a second copy is certain harm, '
                .'a missed mail is possible harm. Check with the recipient if it matters.',
        ]);

        $this->warn(sprintf(
            '%d Nachricht(en) abgeschlossen ohne erneuten Versand — sie waren dem Mailserver bereits '
            .'übergeben, als der Arbeiter starb. Ob sie ankamen, steht im Log.',
            $handedOver->count()
        ));
    }
}
