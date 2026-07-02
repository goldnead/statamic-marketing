<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Events\MessageClicked;
use Goldnead\Marketing\Events\MessageOpened;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;

class TrackingService
{
    public function recordOpen(string $messageUuid): ?Message
    {
        $message = Message::query()->where('uuid', $messageUuid)->first();

        if (! $message) {
            return null;
        }

        $firstOpen = $message->first_opened_at === null;

        $message->forceFill([
            'opens' => $message->opens + 1,
            'first_opened_at' => $message->first_opened_at ?? now(),
            'last_opened_at' => now(),
        ])->save();

        MessageEvent::create([
            'message_id' => $message->id,
            'type' => MessageEvent::TYPE_OPEN,
        ]);

        if ($firstOpen) {
            event(new MessageOpened($message));
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
