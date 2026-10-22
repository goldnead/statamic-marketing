<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;

/**
 * What became of a set of mails, counted in people. Read only.
 *
 * Asked by the automations map for the mails of one step of a series: it knows
 * which messages a step sent (the run's output names the message uuid), and this
 * knows what a message's events mean. The meaning stays here on purpose, in the
 * addon that writes `marketing_message_events.machine`.
 *
 * - **sent**: people with a message of the set that left (`status = sent`).
 * - **opened**: people with an open that was not a machine's, or with a click.
 *   A click is a person and implies the open, also where images never load.
 * - **proxy_only**: people whose only opens are a machine's (Apple's Mail
 *   Privacy Protection fetches the pixel on delivery). Whether they read the
 *   mail is not known, which is why they are neither "opened" nor "not opened".
 * - **clicked**: people with a click. A security scanner that follows a link
 *   cannot be told from a person here; the click events carry no machine flag.
 * - **unsubscribed**: people with an unsubscribe event on one of these messages.
 *
 * A person is the lower-cased address, so two messages to one address, or a
 * hundred opens, count once. Counters on the message (`opens`, `clicks`) are not
 * used: they count the machines too.
 */
class MessageEngagement
{
    /** Below SQLite's variable limit, with room for the status condition. */
    private const CHUNK = 500;

    /**
     * @param  list<string>  $messageUuids
     * @return array{sent: int, opened: int, proxy_only: int, clicked: int, unsubscribed: int}
     */
    public function forMessages(array $messageUuids): array
    {
        $sent = $human = $proxy = $clicked = $unsubscribed = [];

        foreach (array_chunk(array_values(array_unique($messageUuids)), self::CHUNK) as $chunk) {
            $people = Message::query()
                ->whereIn('uuid', $chunk)
                ->where('status', Message::STATUS_SENT)
                ->pluck('email', 'id')
                ->map(fn ($email) => mb_strtolower(trim((string) $email)))
                ->all();

            if ($people === []) {
                continue;
            }

            foreach ($people as $person) {
                $sent[$person] = true;
            }

            $events = MessageEvent::query()
                ->whereIn('message_id', array_keys($people))
                ->whereIn('type', [MessageEvent::TYPE_OPEN, MessageEvent::TYPE_CLICK, MessageEvent::TYPE_UNSUBSCRIBE])
                ->groupBy('message_id', 'type', 'machine')
                ->get(['message_id', 'type', 'machine']);

            foreach ($events as $event) {
                $person = $people[$event->message_id];

                match ($event->type) {
                    MessageEvent::TYPE_OPEN => $event->machine ? $proxy[$person] = true : $human[$person] = true,
                    MessageEvent::TYPE_CLICK => $clicked[$person] = true,
                    default => $unsubscribed[$person] = true,
                };
            }
        }

        $opened = $human + $clicked;

        return [
            'sent' => count($sent),
            'opened' => count($opened),
            'proxy_only' => count(array_diff_key($proxy, $opened)),
            'clicked' => count($clicked),
            'unsubscribed' => count($unsubscribed),
        ];
    }
}
