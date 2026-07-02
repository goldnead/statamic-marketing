<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

class SendCampaignAction implements AutomationAction
{
    public static function handle(): string
    {
        return 'marketing.send_campaign';
    }

    public static function label(): string
    {
        return 'Send Campaign';
    }

    public static function description(): ?string
    {
        return 'Queues a draft or scheduled marketing campaign for immediate delivery.';
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
                'handle' => 'campaign',
                'label' => 'Campaign handle',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $handle = (string) ($config['campaign'] ?? '');

        if (! $handle) {
            return ActionResult::failed('marketing.send_campaign requires a "campaign" handle.');
        }

        $campaign = app(CampaignRepository::class)->find($handle);

        if (! $campaign) {
            return ActionResult::failed("Campaign [{$handle}] does not exist.");
        }

        if ($context->isTestMode()) {
            return ActionResult::success([
                'preview' => true,
                'campaign' => $handle,
                'status' => $campaign->status,
            ]);
        }

        if (! $campaign->isSendable()) {
            return ActionResult::skipped("Campaign [{$handle}] is not sendable (status: {$campaign->status}).");
        }

        try {
            app(CampaignSender::class)->queue($campaign);
        } catch (\Throwable $e) {
            return ActionResult::failed($e->getMessage());
        }

        return ActionResult::success([
            'campaign' => $handle,
            'status' => 'sending',
        ]);
    }
}
