<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Asks whether somebody followed a tracked link in one delivery.
 *
 * The condition half of "wait two days, then ask those who clicked": the node
 * does not wait, a `delay` in front of it does, and a `branch` behind it reads
 * `clicked`. It looks at the stored click events of one message, identified by
 * the `message_uuid` that `marketing.send_email` hands out.
 *
 * ## Mail scanners
 *
 * Security gateways follow every link of an incoming mail within seconds of
 * delivery, and a click is recorded without asking who made it. With
 * `ignore_within_seconds` a click that happened that soon after the mail left
 * does not count. It is a heuristic, not a verdict: a scanner that waits
 * longer, or a person who reads at once, is not told apart.
 *
 * ## It never answers "no" by accident
 *
 * A missing or unknown message is a failure, not `clicked = false`: a flow that
 * sends the "you did not click" mail because the lookup broke is worse than a
 * flow that stops and says so.
 */
class MessageClickedAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'marketing.message_clicked';
    }

    public static function label(): string
    {
        return 'Message Clicked';
    }

    public static function description(): ?string
    {
        return 'Checks whether a tracked link in one delivered message was clicked, optionally only links containing a given text and ignoring clicks right after delivery (mail scanners).';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'message_uuid',
                'label' => 'Message',
                'type' => 'text',
                'required' => true,
                'tokenable' => true,
                'help' => 'The uuid of the delivery to look at, for example {{ nodes.send_1.message_uuid }} from a marketing.send_email node earlier in the flow.',
            ],
            [
                'handle' => 'url_contains',
                'label' => 'Link contains',
                'type' => 'text',
                'required' => false,
                'help' => 'Only clicks on a link whose address contains this text count (plain, case-sensitive substring), for example utm_content=offer. Empty: any link in the mail.',
            ],
            [
                'handle' => 'ignore_within_seconds',
                'label' => 'Ignore clicks within (seconds)',
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'help' => 'Clicks this soon after the mail was sent do not count. Mail scanners follow the links of a message right after delivery; a few minutes filters most of them. 0 counts every click.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'clicked' => 'bool',
            'clicked_at' => 'string',
            'url' => 'string',
            'clicks' => 'int',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $uuid = trim((string) ($config['message_uuid'] ?? ''));
        $needle = trim((string) ($config['url_contains'] ?? ''));
        $ignore = max(0, (int) ($config['ignore_within_seconds'] ?? 0));

        // A test run starts from an empty context: the token resolves to
        // nothing there, and nothing is wrong with the node.
        if ($uuid === '' && $context->isTestMode()) {
            return ActionResult::success([
                'clicked' => false,
                'clicked_at' => null,
                'url' => null,
                'clicks' => 0,
                'note' => 'Test mode — no message to look at.',
            ]);
        }

        if ($uuid === '') {
            return ActionResult::missingDataReference('message_uuid', 'Message', '{{ nodes.send_1.message_uuid }}');
        }

        $message = Message::query()->where('uuid', $uuid)->first();

        if ($message === null) {
            return ActionResult::failed("No message with uuid [{$uuid}].");
        }

        $clicks = MessageEvent::query()
            ->where('message_id', $message->id)
            ->where('type', MessageEvent::TYPE_CLICK)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (MessageEvent $event): bool => $needle === '' || str_contains((string) $event->url, $needle))
            ->filter(fn (MessageEvent $event): bool => ! $this->tooSoon($message, $event, $ignore))
            ->values();

        $first = $clicks->first();

        return ActionResult::success([
            'clicked' => $first !== null,
            'clicked_at' => $first?->created_at?->toIso8601String(),
            'url' => $first?->url,
            'clicks' => $clicks->count(),
        ]);
    }

    private function tooSoon(Message $message, MessageEvent $event, int $seconds): bool
    {
        if ($seconds <= 0 || $message->sent_at === null || $event->created_at === null) {
            return false;
        }

        return $event->created_at->lessThan($message->sent_at->copy()->addSeconds($seconds));
    }
}
