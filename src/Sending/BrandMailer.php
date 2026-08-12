<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\BrandContext\Sending\BrandMailer as BrandContextMailer;
use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\Marketing\Contracts\SenderIdentityResolver;
use Goldnead\Marketing\Mail\CampaignMail;

/**
 * The one door every marketing mail leaves through.
 *
 * The mechanism is {@see BrandContextMailer}: values on the message, never
 * state in the config, a refusal as a return value rather than an exception.
 * Until 12.08.2026 this class carried its own copy of that mechanism, and the
 * copy still worked the old way — `Config::set('marketing.from.*')` inside a
 * `try`/`finally`. That only held as long as every mailable read
 * `marketing.from.*` and nothing else ever put a From on the message, so it was
 * a rule enforced by nothing. The mailables now leave a From that is already on
 * the message alone, which is the same guarantee without the bookkeeping.
 *
 * Two things stay specific to marketing:
 *
 * **`marketing.sending.mailer`** is a documented setting older than any of
 * this, and a host that names a transport there must keep getting it. It is
 * applied in {@see self::transport()} — after the brand, never over it.
 *
 * **The from-address of a campaign** is decided in
 * {@see CampaignMail}, where the message is built,
 * because that is the only place that knows whether the campaign named one.
 */
class BrandMailer extends BrandContextMailer
{
    public function __construct(SenderIdentityResolver $identities)
    {
        parent::__construct($identities);
    }

    /**
     * The brand's transport, then the package's configured one, then Laravel's.
     *
     * The order matters and only one part of it is negotiable. A brand that
     * names a mailer wins, always: it is the half of the pair that has to match
     * the address, and the address came from the same row. Below that,
     * `marketing.sending.mailer` is what this package sent through before
     * brands existed, and returning null here would silently move every
     * single-brand install onto `mail.default`.
     */
    protected function transport(SenderIdentity $identity): ?string
    {
        return $identity->mailer ?? config('marketing.sending.mailer');
    }
}
