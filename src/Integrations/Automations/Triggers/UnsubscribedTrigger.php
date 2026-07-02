<?php

namespace Goldnead\Marketing\Integrations\Automations\Triggers;

class UnsubscribedTrigger extends SubscribedTrigger
{
    public static function handle(): string
    {
        return 'marketing.unsubscribed';
    }

    public static function label(): string
    {
        return 'Subscriber Unsubscribed';
    }

    public static function description(): ?string
    {
        return 'Triggered when someone unsubscribes from a mailing list.';
    }
}
