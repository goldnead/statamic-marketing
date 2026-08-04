<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\Marketing\Contracts\MailClass;

/**
 * Which of the two mails `marketing.send_email` was configured to send.
 *
 * The node has two modes and they are not variations of one another:
 *
 *  - **Campaign mode** names a campaign. The campaign carries the subject, the
 *    content, the layout, the list and the classification — everything the mail
 *    is. This is the mode the node was built for and the only one until 1.12.0.
 *  - **Template mode** names a managed email template (`et_templates`), an
 *    address and a subject. There is no campaign anywhere in it.
 *
 * Exactly one of the two, never both and never neither. Both would be two
 * different answers to "what is this mail"; neither is no answer at all. The
 * node cannot pick for the editor in either case, so both are configuration
 * errors that a test run has to be able to report — which is why this class
 * exists at all rather than a handful of `if`s inside `execute()`.
 *
 * **It lives here, not in the automations bridge.** What a marketing mail needs
 * before it may be sent is marketing's own rule, not the orchestrator's, and
 * putting it beside the node would make it unreachable for anything that does
 * not have `goldnead/statamic-automations` installed — including the tests that
 * hold the rule.
 */
final class SendMode
{
    public const CAMPAIGN = 'campaign';

    public const TEMPLATE = 'template';

    private function __construct(
        public readonly ?string $mode,
        public readonly string $campaign,
        public readonly string $template,
        public readonly string $subject,
        public readonly string $list,
        public readonly MailClass $mailClass,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $campaign = self::text($config['campaign'] ?? null);
        $template = self::text($config['template'] ?? null);
        $subject = self::text($config['subject'] ?? null);
        $list = self::text($config['list'] ?? null);

        // Unknown, absent or misspelt reads as `marketing`, the same
        // conservative direction a campaign's own field takes: a mail nobody
        // classified is capped rather than exempt.
        $class = MailClass::fromValue(self::text($config['mail_class'] ?? null) ?: null);

        $make = fn (?string $mode, ?string $error = null) => new self(
            $mode, $campaign, $template, $subject, $list, $class, $error,
        );

        if ($campaign !== '' && $template !== '') {
            return $make(null,
                'marketing.send_email takes either a "campaign" or a "template", not both. '
                ."This node names campaign [{$campaign}] and template [{$template}], which are two "
                .'different descriptions of the same mail — a campaign already carries its own '
                .'subject, content and layout. Clear whichever one is not meant.'
            );
        }

        if ($campaign === '' && $template === '') {
            return $make(null,
                'marketing.send_email needs either a "campaign" (campaign mode) or a "template" '
                .'(template mode). Neither is set, so this node has no mail to send.'
            );
        }

        if ($campaign !== '') {
            // The list stays optional here: campaign mode falls back to the
            // campaign's own, which is where it has always come from.
            return $make(self::CAMPAIGN);
        }

        if ($list === '') {
            return $make(null,
                'marketing.send_email in template mode requires a "list". The mailing list is where '
                .'consent for this mail comes from, and a template names no list of its own the way a '
                .'campaign does. Sending without one would mean sending marketing mail with nothing to '
                .'show that the recipient ever agreed to receive it.'
            );
        }

        if ($subject === '') {
            return $make(null,
                'marketing.send_email in template mode requires a "subject". A campaign carries its '
                .'own; a template is a layout, and the line the recipient sees in their inbox has to '
                .'be stated here.'
            );
        }

        return $make(self::TEMPLATE);
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    public function isCampaign(): bool
    {
        return $this->mode === self::CAMPAIGN;
    }

    public function isTemplate(): bool
    {
        return $this->mode === self::TEMPLATE;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
