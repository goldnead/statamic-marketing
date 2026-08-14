<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Events\MessageClicked;
use Goldnead\Marketing\Events\MessageOpened;
use Goldnead\Marketing\Events\MessageOpenedByHuman;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Support\MachineOpen;

class TrackingService
{
    /**
     * @param  string|null  $userAgent  The fetching client, used once and never
     *                                  stored. Whether it was a person or a
     *                                  provider's prefetcher is decided here,
     *                                  where the message is already loaded —
     *                                  see {@see MachineOpen} for how far that
     *                                  can be known, which is not very.
     */
    public function recordOpen(string $messageUuid, ?string $userAgent = null): ?Message
    {
        $message = Message::query()->where('uuid', $messageUuid)->first();

        if (! $message) {
            return null;
        }

        $machine = MachineOpen::looksAutomated($userAgent, $message);

        $firstOpen = $message->first_opened_at === null;

        // The counters keep counting everything, machine or not. They are what
        // the campaign report compares against every other campaign and against
        // whatever the previous tool said; quietly redefining them here would
        // make this release look like a drop in engagement.
        $message->forceFill([
            'opens' => $message->opens + 1,
            'first_opened_at' => $message->first_opened_at ?? now(),
            'last_opened_at' => now(),
        ])->save();

        // Asked before the row is written, or it would find itself.
        $firstHumanOpen = ! $machine && ! MessageEvent::query()
            ->where('message_id', $message->id)
            ->where('type', MessageEvent::TYPE_OPEN)
            ->where('machine', false)
            ->exists();

        MessageEvent::create([
            'message_id' => $message->id,
            'type' => MessageEvent::TYPE_OPEN,
            'machine' => $machine,
        ]);

        // `metadata` rather than a second event class: consumers that only care
        // that it was opened keep working, and the one that cares whether a
        // person did it can ask.
        if ($firstOpen) {
            event(new MessageOpened($message, ['machine' => $machine]));
        }

        // The reading, as opposed to the fetching. For a mailbox behind a
        // scanner the first open is practically always the machine's, and
        // `MessageOpened` has then already fired and will not fire again — so
        // without this the person's own open is recorded in the events table
        // and announced to nobody.
        if ($firstHumanOpen) {
            event(new MessageOpenedByHuman($message, ['machine' => false]));
        }

        return $message;
    }

    public function recordClick(string $messageUuid, string $url): ?Message
    {
        $message = Message::query()->where('uuid', $messageUuid)->first();

        if (! $message) {
            return null;
        }

        $firstClick = $message->first_clicked_at === null;

        $message->forceFill([
            'clicks' => $message->clicks + 1,
            'first_clicked_at' => $message->first_clicked_at ?? now(),
            'last_clicked_at' => now(),
            // A click implies the mail was opened, even when images are blocked.
            'first_opened_at' => $message->first_opened_at ?? now(),
            'last_opened_at' => now(),
        ])->save();

        MessageEvent::create([
            'message_id' => $message->id,
            'type' => MessageEvent::TYPE_CLICK,
            'url' => $url,
        ]);

        if ($firstClick) {
            event(new MessageClicked($message, ['url' => $url]));
        }

        return $message;
    }

    /** A 1x1 transparent GIF, served for the open pixel. */
    public static function pixel(): string
    {
        return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
}
