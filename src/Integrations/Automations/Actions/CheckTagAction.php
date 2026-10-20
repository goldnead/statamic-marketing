<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Integrations\Automations\ReadsRunAddress;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Asks whether the subscription of this address on one list carries a tag.
 *
 * Tags are where `TagLinks` puts them. With LeadHub installed they are on the
 * person's contact, so the contact is read (by the subscription's contact uuid,
 * then by email, the order `TagLinks` writes in). Without LeadHub they are
 * `meta.tags` on the subscription. The node reads what the link wrote.
 *
 * ## Outputs
 *
 *  - `has_tag`: the tag is on the person. Independent of the list status, so a
 *    person who unsubscribed keeps the tag they earned while subscribed.
 *  - `subscribed`: the subscription on this list is currently active.
 *  - `tags`: every tag read, for a branch or a log line.
 *
 * No subscription on the list is an answer, not a failure: `has_tag` and
 * `subscribed` are both false.
 *
 * ## Modes
 *
 * As `marketing.check_received`: `report` never stops, `require` stops when the
 * tag is missing, `forbid` stops when it is there. A stop is an ordinary
 * outcome; an unreadable tag set or an unknown list is `failed`.
 */
class CheckTagAction implements AutomationAction
{
    use ReadsRunAddress;

    public const MODE_REPORT = 'report';

    public const MODE_REQUIRE = 'require';

    public const MODE_FORBID = 'forbid';

    public static function handle(): string
    {
        return 'marketing.check_tag';
    }

    public static function label(): string
    {
        return 'Check Subscriber Tag';
    }

    public static function description(): ?string
    {
        return 'Checks whether the subscription of this address on a list carries a tag, and can end the run on the answer.';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            [
                'handle' => 'mode',
                'label' => 'Continue only if the subscriber',
                'type' => 'select',
                'required' => true,
                'default' => self::MODE_REPORT,
                'options' => [
                    self::MODE_REPORT => 'any (just report, never stop)',
                    self::MODE_REQUIRE => 'has the tag',
                    self::MODE_FORBID => 'does not have the tag',
                ],
                'help' => 'When the condition does not hold, the run ends here. That is a stop, not a failure. "Just report" never stops: the node outputs has_tag and a branch node splits on it.',
            ],
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'The address whose subscription is looked at. Leave empty to use the address the run is about ({{ subscriber.email }}, {{ contact.email }} or {{ email }}).',
            ],
            [
                'handle' => 'list',
                'label' => 'Mailing list',
                'type' => 'select',
                'options' => SendMarketingEmailAction::listOptions(),
                'options_source' => 'marketing.lists',
                'required' => true,
                'help' => 'The list whose subscription carries the tag.',
            ],
            [
                'handle' => 'tag',
                'label' => 'Tag',
                'type' => 'text',
                'required' => true,
                'help' => 'The tag exactly as the tag link puts it on the person, for example wants-freebie.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'has_tag' => 'bool',
            'subscribed' => 'bool',
            'tags' => 'list',
            'mode' => 'string',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $mode = trim((string) ($config['mode'] ?? self::MODE_REPORT)) ?: self::MODE_REPORT;
        $tag = trim((string) ($config['tag'] ?? ''));
        $listHandle = trim((string) ($config['list'] ?? ''));

        // Static configuration first: a node without a list or a tag, or with a
        // mode nobody defined, is broken, and a test run has to say so.
        if (! in_array($mode, [self::MODE_REPORT, self::MODE_REQUIRE, self::MODE_FORBID], true)) {
            return ActionResult::failed(sprintf('Unknown mode "%s": use "report", "require" or "forbid".', $mode));
        }

        if ($tag === '') {
            return ActionResult::failed('Tag is required.');
        }

        if ($listHandle === '') {
            return ActionResult::failed('Mailing list is required.');
        }

        $list = app(MailingListRepository::class)->find($listHandle);

        if ($list === null) {
            return ActionResult::failed(sprintf('Unknown mailing list [%s].', $listHandle));
        }

        $email = $this->addressOf($context, $config);

        if ($email === '' && $context->isTestMode()) {
            return ActionResult::success([
                'has_tag' => false,
                'subscribed' => false,
                'tags' => [],
                'mode' => $mode,
                'note' => 'Test mode — no address to look at.',
            ]);
        }

        if ($email === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ subscriber.email }}');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failed('A valid "email" is required.', ['mode' => $mode]);
        }

        try {
            $subscription = Subscription::query()
                ->forList($list->handle)
                ->where('email_normalized', (string) EmailNormalizer::normalize($email))
                ->first();

            $tags = $subscription === null ? [] : $this->tagsOf($subscription);
        } catch (\Throwable $e) {
            return ActionResult::failed('Reading the subscriber tags failed: '.$e->getMessage(), ['mode' => $mode]);
        }

        $hasTag = in_array($tag, $tags, true);
        $subscribed = $subscription?->isSubscribed() ?? false;

        $output = [
            'has_tag' => $hasTag,
            'subscribed' => $subscribed,
            'tags' => $tags,
            'mode' => $mode,
        ];

        if ($mode === self::MODE_REQUIRE && ! $hasTag) {
            return ActionResult::stopped(sprintf('%s does not carry the tag "%s" on [%s], so the run ends here.', $email, $tag, $list->handle));
        }

        if ($mode === self::MODE_FORBID && $hasTag) {
            return ActionResult::stopped(sprintf('%s already carries the tag "%s" on [%s], so the run ends here.', $email, $tag, $list->handle));
        }

        return ActionResult::success($output);
    }

    /**
     * The tag names on the person behind a subscription, read where TagLinks
     * writes them.
     *
     * @return list<string>
     */
    protected function tagsOf(Subscription $subscription): array
    {
        if (class_exists(LeadHub::class)) {
            $contact = $subscription->contact_uuid ? LeadHub::find((string) $subscription->contact_uuid) : null;
            $contact ??= LeadHub::findByEmail((string) $subscription->email);

            return array_values(array_map('strval', (array) ($contact['tags'] ?? [])));
        }

        return array_values(array_map('strval', (array) (($subscription->meta ?? [])['tags'] ?? [])));
    }
}
