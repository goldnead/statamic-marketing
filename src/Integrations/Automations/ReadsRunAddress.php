<?php

namespace Goldnead\Marketing\Integrations\Automations;

use Goldnead\StatamicAutomations\Context\AutomationContext;

/**
 * The address a node checks when its `email` field is left empty: the address
 * the run is about, found the same way `marketing.send_email` finds it.
 */
trait ReadsRunAddress
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function addressOf(AutomationContext $context, array $config): string
    {
        $configured = trim((string) ($config['email'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        foreach (['subscriber.email', 'contact.email', 'lead.email', 'email'] as $path) {
            $candidate = $context->get($path);

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
