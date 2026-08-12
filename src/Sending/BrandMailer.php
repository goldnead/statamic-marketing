<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\Marketing\Contracts\SenderIdentityResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use LogicException;

/**
 * The one door every marketing mail leaves through.
 *
 * Before this class the four send paths — campaign fan-out, single send, test
 * send, double-opt-in confirmation — each read `config('marketing.sending.mailer')`
 * directly. That value is global, so in a multi-brand install every brand sent
 * over the same transport with the same From. The failure is not cosmetic: a
 * relay that verifies sending domains per account replaces a From it does not
 * own, and the recipient gets a confirmation for brand A's newsletter under
 * brand B's name and address. That happened (chorgesucht's double-opt-in mail
 * went out through the FamilyStack Scaleway project, 12.08.2026), which is why
 * the resolution now happens in one place instead of four.
 *
 * **The overrides are scoped, not set.** A queue worker sends thousands of
 * messages in one process, for more than one brand. Anything written to config
 * here is restored in a `finally` — a throwing send may not leave the next
 * brand's mail holding this brand's From.
 *
 * **Locale travels on the mailable**, not on the app. `Mailable::locale()` is
 * what Laravel wraps the whole render in; setting `app()->setLocale()` around
 * the send would also change the locale of anything the render triggers.
 */
class BrandMailer
{
    public function __construct(protected SenderIdentityResolver $identities) {}

    /**
     * Send one mailable as the given brand.
     *
     * @param  int|null  $brandId  null = the brand in context, if any.
     */
    public function send(?int $brandId, string $to, Mailable $mailable): void
    {
        // A queued mailable would leave this window and be built later, in
        // another process, with none of this in place — the identity would be
        // silently gone. Marketing already queues at the job level
        // (`SendMessageJob`), which is where the brand is carried on the row.
        if ($mailable instanceof ShouldQueue) {
            throw new LogicException(
                'A ShouldQueue mailable cannot carry a brand sender identity: it is built after this '
                .'send returns, when the identity is no longer in place. Queue the surrounding job '
                .'instead — see SendMessageJob, which keeps the brand on the message row. ('
                .$mailable::class.')'
            );
        }

        $identity = $this->identities->resolve($brandId);

        // An explicit locale on the mailable wins: the caller knew something
        // the brand row does not.
        if ($identity->locale && ! $mailable->locale) {
            $mailable->locale($identity->locale);
        }

        $this->withIdentity($identity, function () use ($identity, $to, $mailable): void {
            Mail::mailer($identity->mailer)
                ->to($to)
                ->send($mailable);
        });
    }

    /**
     * Run a callback with this identity's config in place, then put back what
     * was there.
     *
     * Only `marketing.from.*` is touched — see `SenderIdentity::configOverrides()`
     * for why `mail.from.*` must not be. Both keys are declared in the shipped
     * config file, so restoring a previous `null` restores the state that was
     * actually there rather than inventing one.
     */
    protected function withIdentity(SenderIdentity $identity, callable $callback): mixed
    {
        $overrides = $identity->configOverrides();

        if ($overrides === []) {
            return $callback();
        }

        $previous = [];

        foreach (array_keys($overrides) as $key) {
            $previous[$key] = Config::get($key);
        }

        Config::set($overrides);

        try {
            return $callback();
        } finally {
            Config::set($previous);
        }
    }
}
