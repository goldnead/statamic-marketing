<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\SenderIdentityResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the sender identity out of `brands.settings.mail`.
 *
 * ```
 * settings->mail->from_address
 * settings->mail->from_name     (defaults to the brand name)
 * settings->mail->mailer        (a mailer from config/mail.php)
 * settings->mail->locale        (the language its mail is written in)
 * ```
 *
 * **Why the transport belongs here and the credentials do not.** One relay
 * account verifies one set of sending domains. The hub learned this the
 * expensive way: a brand whose domain lived in a different Scaleway project
 * sent through the account that did not own it, and the provider substituted
 * its own verified From — the mail went out under a *foreign brand's* name.
 * A mailer per sending domain in `config/mail.php`, selected per brand here,
 * is what prevents that. The SMTP credentials stay in the environment; putting
 * them in `settings` would carry them into the database, every backup and
 * every CP export.
 *
 * **A brand with no `settings.mail` changes nothing.** It resolves to the pure
 * config identity, so a single-brand install — and every multi-brand install
 * that has not filled the settings in — sends exactly as it did before this
 * class existed. Only once a brand declares a mail identity do the brand-level
 * defaults (from_name ← brand name) apply.
 *
 * **A brand that names a mailer but no address is a misconfiguration**, and it
 * is treated as one: the pair is the whole point, and splitting it puts a
 * brand's transport behind the host-wide From — which is the 12.08. incident
 * with the halves swapped. The brand's transport is still used, because a
 * relay refusing an address it does not own is a loud failure, and a loud
 * failure beats quietly delivering under somebody else's name. It is logged
 * once per brand per process so the reason is findable.
 *
 * The other direction is legitimate and stays silent: an address without a
 * mailer means "my domain is verified in the account the app already uses",
 * which is the ordinary case for the brand the global credentials belong to.
 */
class BrandSenderIdentity implements SenderIdentityResolver
{
    public function resolve(?int $brandId): SenderIdentity
    {
        $brand = $this->brand($brandId);

        if (! $brand) {
            return SenderIdentity::fromConfig();
        }

        $mail = data_get($brand->settings, 'mail');
        $mail = is_array($mail) ? $mail : [];

        if ($mail === []) {
            // The brand exists but says nothing about mail. Nothing changes —
            // including the locale, which stays whatever the app decided.
            return SenderIdentity::fromConfig();
        }

        $declaredAddress = $this->string(data_get($mail, 'from_address'));
        $declaredMailer = $this->string(data_get($mail, 'mailer'));

        if ($declaredMailer && ! $declaredAddress) {
            $this->warnOnce($brand);
        }

        $fromAddress = $declaredAddress ?: config('marketing.from.email');

        return new SenderIdentity(
            mailer: $declaredMailer ?: config('marketing.sending.mailer'),
            fromAddress: $fromAddress,
            fromName: $this->string(data_get($mail, 'from_name'))
                ?: ($this->string($brand->name) ?: config('marketing.from.name')),
            locale: $this->string(data_get($mail, 'locale'))
                ?: $this->string(data_get($brand->settings, 'locale')),
        );
    }

    /**
     * The brand a mail belongs to, or null when there is nothing to look up.
     *
     * Fails soft by design: a missing brands table (an install mid-migration),
     * a deleted brand, a queue worker with no brand in context — none of those
     * may stop a mail that would have gone out before. They all mean "use the
     * configured identity".
     */
    protected function brand(?int $brandId): ?Brand
    {
        try {
            if ($brandId !== null) {
                // Brands are the scoping root and carry no brand scope of
                // their own, so this needs no escape hatch.
                return Brand::query()->find($brandId);
            }

            return BrandContext::hasCurrent() ? BrandContext::current() : null;
        } catch (Throwable $e) {
            // Throttled for the same reason as warnOnce(): a database that is
            // unreachable during a fan-out is one incident, not fifty thousand
            // of them. Keyed by exception class and re-armed after the window,
            // NOT silenced for the life of the process — a `queue:work` runs
            // for days, and this path answers a failed lookup by falling back
            // to the global identity. That is precisely the mis-attribution
            // this class exists to prevent, so the second, different failure
            // has to be as visible as the first.
            if (static::shouldSay('lookup:'.$e::class)) {
                report($e);
            }

            return null;
        }
    }

    /**
     * When each throttled message was last said, keyed by subject.
     *
     * A campaign fan-out asks this question for every message in the batch. An
     * untrottled warning would write one log line per recipient — fifty
     * thousand copies of the same sentence, which is how a real warning gets
     * scrolled past.
     *
     * @var array<string, float>
     */
    protected static array $warned = [];

    /**
     * How long a message stays said. Long enough to collapse a fan-out, short
     * enough that a worker running for days keeps reporting.
     */
    protected const SAY_AGAIN_AFTER_SECONDS = 300;

    protected static function shouldSay(string $key): bool
    {
        $now = microtime(true);

        if (isset(static::$warned[$key]) && ($now - static::$warned[$key]) < static::SAY_AGAIN_AFTER_SECONDS) {
            return false;
        }

        static::$warned[$key] = $now;

        return true;
    }

    protected function warnOnce(Brand $brand): void
    {
        // Namespaced so a brand key can never collide with a lookup-failure
        // slot.
        if (! static::shouldSay('brand:'.($brand->getKey() ?? $brand->handle ?? '?'))) {
            return;
        }

        Log::warning(sprintf(
            'Brand [%s] names settings.mail.mailer but no settings.mail.from_address. Its mail goes out '
            .'over the brand transport with the host-wide From, which a relay that verifies sending '
            .'domains per account will refuse. Set from_address on the brand.',
            $brand->handle ?? $brand->getKey()
        ));
    }

    /**
     * Forget what has been said, so the next occurrence is reported again.
     *
     * The suite needs it because the static state outlives a test and brand
     * ids are recycled. A long-running worker can use it as a re-arm hook
     * after a restart of whatever it was waiting on.
     */
    public static function forgetWarnings(): void
    {
        static::$warned = [];
    }

    protected function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
