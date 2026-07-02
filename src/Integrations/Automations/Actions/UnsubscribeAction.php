<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

class UnsubscribeAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'marketing.unsubscribe';
    }

    public static function label(): string
    {
        return 'Unsubscribe from Mailing List';
    }

    public static function description(): ?string
    {
        return 'Removes an email address from a marketing mailing list.';
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
                'handle' => 'list',
                'label' => 'List handle',
                'type' => 'text',
                'required' => true,
            ],
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => true,
                'help' => 'Supports tokens, e.g. {{ subscriber.email }}.',
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $listHandle = (string) ($config['list'] ?? '');
        $email = (string) ($config['email'] ?? '');

        if (! $listHandle || ! $email) {
            return ActionResult::failed('marketing.unsubscribe requires both "list" and "email".');
        }

        $subscription = Subscription::forList($listHandle)
            ->where('email_normalized', EmailNormalizer::normalize($email))
            ->first();

        if (! $subscription) {
            return ActionResult::skipped("No subscription for [{$email}] on list [{$listHandle}].");
        }

        if ($context->isTestMode()) {
            return ActionResult::success([
                'preview' => true,
                'list' => $listHandle,
                'email' => $email,
            ]);
        }

        app(SubscriptionService::class)->unsubscribe($subscription, ['reason' => 'automation']);

        return ActionResult::success([
            'subscription_uuid' => $subscription->uuid,
            'list' => $listHandle,
            'email' => $subscription->email,
        ]);
    }
}
