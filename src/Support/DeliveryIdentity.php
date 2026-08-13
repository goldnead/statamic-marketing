<?php

namespace Goldnead\Marketing\Support;

/**
 * Which mailbox will physically receive this, regardless of how the address
 * was written.
 *
 * This is NOT the consent identity. `Leadhub\Support\EmailNormalizer` — and
 * with it `Subscription::uniquenessKeyFor()` and the consent unique — trims and
 * lowercases and deliberately stops there, because merging two addresses in
 * the consent record means merging two people's decisions, and
 * `sales@firma.de` and `sales+shop@firma.de` may well be two people. Being
 * conservative there is correct.
 *
 * An abuse limit has the opposite failure mode. It has to answer "how much
 * mail is landing in one human's inbox", and every address below reaches the
 * same inbox:
 *
 *     Opfer@Example.com          case
 *     opfer+1@example.com        subaddressing (RFC 5233), all major providers
 *     o.p.f.e.r@gmail.com        Gmail ignores dots in the local part
 *     opfer@googlemail.com       the same Gmail mailbox under its alias domain
 *     ｏｐｆｅｒ@example.com        fullwidth forms, collapsed by NFKC
 *
 * A limit keyed on the consent identity would count each of those separately,
 * which is to say it would not be a limit at all: an attacker increments the
 * tag and keeps sending. That is exactly the hole this class exists to close,
 * and it is why the aggressive rules live here rather than in the normalizer.
 *
 * The cost of being aggressive is bounded and runs the safe way. The worst
 * case is a false MERGE — two genuinely distinct mailboxes counted as one, so
 * the second person waits for the window to pass before their confirmation
 * mail is sent. The subscription row is written either way and a second
 * attempt sends it, so nobody is locked out; they are delayed. The opposite
 * error, a false SPLIT, is what a mail cannon is made of.
 *
 * What this deliberately does NOT do: Google Workspace domains ignore dots
 * exactly like gmail.com does, and no rule can tell such a domain from any
 * other without an MX lookup. Putting DNS in the path of a public form is a
 * dependency and a delay for a hole an attacker still only walks through one
 * custom domain at a time. Some hosts also use `-` as a subaddress separator;
 * that is not applied, because `-` is an ordinary character in far too many
 * real local parts and cutting on it would merge unrelated people wholesale.
 */
class DeliveryIdentity
{
    /**
     * Domains that are the same mailbox under a different name.
     */
    protected const DOMAIN_ALIASES = [
        'googlemail.com' => 'gmail.com',
    ];

    /**
     * Domains whose local part ignores dots.
     */
    protected const DOT_INSENSITIVE = [
        'gmail.com',
    ];

    /**
     * A stable, opaque key for the mailbox an address delivers to.
     *
     * Hashed rather than returned in the clear because the caller's use for it
     * is a cache key, and cache keys turn up in `redis-cli --scan`, in slow-log
     * output and in whatever dump lands in a ticket. A rate limit on a mailbox
     * has no need to name the mailbox.
     */
    public static function keyFor(?string $email): string
    {
        return hash('sha256', static::canonicalize($email));
    }

    /**
     * The readable form behind `keyFor()`. Public for tests and for anyone
     * debugging why two addresses did or did not share a bucket; nothing in
     * the sending path should log it.
     */
    public static function canonicalize(?string $email): string
    {
        $value = static::foldWidth(trim((string) $email));

        if ($value === '') {
            return '';
        }

        // The last `@` is the separator: a quoted local part may legally
        // contain one, a domain never can.
        $at = mb_strrpos($value, '@');

        if ($at === false) {
            return mb_strtolower($value);
        }

        $local = mb_substr($value, 0, $at);
        $domain = static::canonicalizeDomain(mb_substr($value, $at + 1));

        return static::canonicalizeLocal($local, $domain).'@'.$domain;
    }

    protected static function canonicalizeDomain(string $domain): string
    {
        // A trailing dot is the fully qualified form of the same name.
        $domain = mb_strtolower(rtrim($domain, '.'));

        // Unicode domains reach the same nameservers as their punycode form,
        // so they have to reduce to one string. Guarded because ext-intl is
        // not guaranteed; without it a Unicode domain simply keeps its
        // lowercased Unicode form, which still folds case and width.
        if (! static::isAscii($domain) && function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $domain = $ascii;
            }
        }

        return static::DOMAIN_ALIASES[$domain] ?? $domain;
    }

    protected static function canonicalizeLocal(string $local, string $domain): string
    {
        $local = mb_strtolower($local);

        /*
         * A quoted local part addresses the same mailbox as the bare one.
         * `"opfer"@example.com` passes Laravel's `email` rule, is accepted by
         * Symfony's Address, is unquoted by the receiving server at delivery,
         * and lands in the same inbox as `opfer@example.com` — while hashing
         * to a different bucket, which is a free doubling of every limit here
         * for the price of two characters. Unescaped as well, because
         * `"op\fer"` is `opfer` once the quotes come off.
         */
        if (mb_strlen($local) >= 2 && str_starts_with($local, '"') && str_ends_with($local, '"')) {
            $local = stripslashes(mb_substr($local, 1, mb_strlen($local) - 2));
        }

        // Subaddressing: everything from the first `+` to the `@` is a label
        // the recipient's server strips before delivery.
        if (($plus = mb_strpos($local, '+')) !== false) {
            $local = mb_substr($local, 0, $plus);
        }

        if (in_array($domain, static::DOT_INSENSITIVE, true)) {
            $local = str_replace('.', '', $local);
        }

        return $local;
    }

    /**
     * Collapse compatibility forms — fullwidth Latin, ligatures, and the rest
     * of NFKC — so that a visually identical address is a textually identical
     * one. Without ext-intl the string is returned unchanged; the case and
     * subaddress rules above still apply, so the common bypasses stay closed.
     */
    protected static function foldWidth(string $value): string
    {
        if (static::isAscii($value) || ! class_exists(\Normalizer::class)) {
            return $value;
        }

        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);

        return is_string($normalized) ? $normalized : $value;
    }

    protected static function isAscii(string $value): bool
    {
        return preg_match('//u', $value) === 1 && ! preg_match('/[^\x00-\x7F]/', $value);
    }
}
