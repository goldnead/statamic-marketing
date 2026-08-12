<?php

namespace Goldnead\Marketing\Sending;

/**
 * Who a marketing mail goes out as, and over which transport.
 *
 * Every value is nullable and every null means "whatever was true before this
 * class existed": the configured mailer, the configured from-address, the
 * application locale. A single-brand install resolves an identity in which all
 * four are the config values and nothing about the send changes.
 *
 * The reason this is a value object rather than four `config()` reads at the
 * call site: the from-address and the transport have to agree. A relay that
 * verifies sending domains per account (Scaleway TEM, Postmark, SES with a
 * verified identity) rejects — or silently rewrites — a From it does not own.
 * Resolving them together makes the pair impossible to split by accident.
 */
class SenderIdentity
{
    public function __construct(
        public readonly ?string $mailer = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly ?string $locale = null,
    ) {}

    /**
     * The identity as it was before per-brand sending: everything from config.
     */
    public static function fromConfig(): self
    {
        return new self(
            mailer: config('marketing.sending.mailer'),
            fromAddress: config('marketing.from.email'),
            fromName: config('marketing.from.name'),
            locale: null,
        );
    }

    /**
     * Config values the mail layer should see while this identity sends.
     *
     * `marketing.from.*` only, deliberately **not** `mail.from.*`. Laravel's
     * MailManager reads `mail.from` the first time a mailer name is resolved
     * and burns it into the mailer instance via `alwaysFrom()`; that instance
     * is then cached for the life of the process. An override of `mail.from.*`
     * inside a scoped window therefore escapes the window: whichever brand
     * happened to send first would leave its address standing in the cached
     * mailer, and the next message through it that sets no From of its own
     * would go out as that brand. That is the bug this class exists to fix,
     * reintroduced one layer down.
     *
     * Nothing is lost by leaving it out. `ConfirmSubscriptionMail` reads
     * `marketing.from.email` first, and `CampaignMail` reads it as soon as the
     * campaign names no address of its own — so the identity reaches the
     * message. When every one of those is empty, `Mailable::from()` is a no-op
     * and the message goes out under `mail.from`, which is what Laravel had
     * already burned into the mailer: exactly the behaviour of before.
     *
     * Only non-null values are returned: a null must not overwrite a configured
     * fallback with nothing.
     *
     * @return array<string, string>
     */
    public function configOverrides(): array
    {
        return array_filter([
            'marketing.from.email' => $this->fromAddress,
            'marketing.from.name' => $this->fromName,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
