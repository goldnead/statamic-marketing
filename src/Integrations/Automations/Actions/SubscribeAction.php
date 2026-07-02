<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

class SubscribeAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'marketing.subscribe';
    }

    public static function label(): string
    {
        return 'Subscribe to Mailing List';
    }

    public static function description(): ?string
    {
        return 'Adds an email address to a marketing mailing list (honours the list\'s double opt-in setting).';
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
                'help' => 'Supports tokens, e.g. {{ lead.email }}.',
            ],
            [
                'handle' => 'first_name',
                'label' => 'First name',
                'type' => 'text',
                'required' => false,
            ],
            [
                'handle' => 'last_name',
                'label' => 'Last name',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $listHandle = (string) ($config['list'] ?? '');
        $email = (string) ($config['email'] ?? '');

        if (! $listHandle || ! $email) {
            return ActionResult::failed('marketing.subscribe requires both "list" and "email".');
        }

        $list = app(MailingListRepository::class)->find($listHandle);

        if (! $list) {
            return ActionResult::failed("Mailing list [{$listHandle}] does not exist.");
        }

        if ($context->isTestMode()) {
            return ActionResult::success([
                'preview' => true,
                'list' => $listHandle,
                'email' => $email,
            ]);
        }

        $subscription = app(SubscriptionService::class)->subscribe(
            $list,
            $email,
            [
                'first_name' => $config['first_name'] ?? null,
                'last_name' => $config['last_name'] ?? null,
            ],
            ['source' => 'automation'],
        );

        return ActionResult::success([
            'subscription_uuid' => $subscription->uuid,
            'status' => $subscription->status,
            'list' => $listHandle,
            'email' => $subscription->email,
        ]);
    }
}
