<?php

namespace Goldnead\Marketing\Support;

use Symfony\Component\Mime\Email;

/**
 * The per-message headers that ask a sending platform to leave the links alone.
 *
 * The other half of the answer to a rewritten link. `TrackingParameters` makes
 * the tracking redirect survive being rewritten; this stops the rewriting on
 * the providers that offer a switch for it — Mailgun, Postmark, Mailjet,
 * SparkPost, SendGrid, Mandrill. On Brevo it does nothing at all: Brevo has no
 * such header and the ignore list is the only thing that works there. See
 * `config/marketing.php` for the table of names, and for who is missing from it.
 *
 * Configured as an arbitrary map rather than modelled, because these are
 * vendor headers this addon should not need to know about one by one, and
 * because Laravel's own `Headers` object carries only the three it knows.
 * Nothing is set by default: an addon that guessed the provider and changed how
 * it behaves would be worse than one that asks.
 */
final class DeliveryHeaders
{
    /**
     * The configured map, reduced to what can actually become a header.
     *
     * @return array<string, string>
     */
    public static function configured(): array
    {
        $headers = [];

        foreach ((array) config('marketing.delivery.mail_headers', []) as $name => $value) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $headers[trim($name)] = (string) $value;
        }

        return $headers;
    }

    /** Add them verbatim, without displacing a header the message already set. */
    public static function applyTo(Email $message): void
    {
        $headers = $message->getHeaders();

        foreach (static::configured() as $name => $value) {
            if ($headers->has($name)) {
                continue;
            }

            $headers->addTextHeader($name, $value);
        }
    }
}
