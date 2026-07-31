<?php

namespace Goldnead\Marketing\Support;

/**
 * The query parameters a mail service provider adds to the click-tracking link
 * in transit, and which of them the signature check is allowed to overlook.
 *
 * Why this exists. Brevo rewrites every `href` in the HTML part of a message
 * onto its own click counter, and when that counter forwards the reader it
 * appends `_se` — the recipient address in base64 — to the target URL. Laravel
 * signs the whole query string, so the URL that arrives is not the URL that was
 * signed and `ValidateSignature` answers 403 before the controller runs.
 * Measured, not assumed: without a signature 403, with a valid signature no
 * longer 403, with a valid signature plus `_se` a 403 again. The damage is
 * double — the reader never reaches the destination, and the click is not
 * counted either, because the middleware aborts ahead of `recordClick()`.
 * Confirmation and unsubscribe links survive the same treatment, since their
 * token sits in the path and nothing about them is signed.
 *
 * What ignoring costs, and why this route pays less than it looks. Whatever is
 * listed here is no longer covered by the signature, so anybody may set it to
 * anything. That is affordable only because nothing this endpoint acts on is
 * ignorable:
 *
 *   1. `url` is the destination. Unlike a magic link, whose payload is in the
 *      path, this route carries where it sends the reader *in the query*. A
 *      `url` on the ignore list would not be a weakened signature, it would be
 *      an open redirect on the sender's own domain, with the sender's own
 *      reputation behind it — `?url=https://boese.example` and away.
 *   2. `expires` is the lifetime. Ignoring it would hand out the right to
 *      choose the value; `signatureHasNotExpired()` reads whatever is there.
 *   3. `signature` is the guarantee itself.
 *
 * All three are therefore enforced here rather than trusted: they are stripped
 * out of whatever a host configures, in any casing, and no comma-separated
 * smuggling puts them back. This is the one addition over the same guard in
 * `statamic-preference-center`, where `url` does not exist.
 *
 * The list is a list, not a rule. An unknown parameter is still refused; only
 * the named ones are overlooked, and each name in the shipped default says
 * which provider adds it.
 */
final class TrackingParameters
{
    /**
     * Never ignorable, whatever the config says.
     *
     * `signature` is redundant — Laravel removes it from the string it verifies
     * on its own — and is listed anyway so that the guard reads as the complete
     * answer to "which parameters carry the guarantee".
     */
    public const RESERVED = ['url', 'expires', 'signature'];

    /**
     * The ignore list as the middleware wants it: a clean, unique, ordered list.
     *
     * Names are filtered to `[A-Za-z0-9_.-]` because this list is passed to the
     * router as a comma-separated middleware argument. A name with a comma in it
     * would not be one ignored parameter but two, one of them invented — and
     * `'_se2,url'` is exactly how a reserved name would otherwise walk back in.
     *
     * @return list<string>
     */
    public static function ignored(): array
    {
        $configured = config('marketing.delivery.ignored_query_parameters', []);

        $clean = [];

        foreach ((array) $configured as $parameter) {
            if (! is_string($parameter) && ! is_numeric($parameter)) {
                continue;
            }

            $parameter = trim((string) $parameter);

            if (preg_match('/^[A-Za-z0-9_.-]+$/', $parameter) !== 1) {
                continue;
            }

            if (static::isReserved($parameter)) {
                continue;
            }

            // `relative` is not a parameter name but the router's own switch
            // between absolute and relative signature checking. Passed through
            // as an ignore entry it would quietly drop the host from what is
            // verified.
            if (strtolower($parameter) === 'relative') {
                continue;
            }

            $clean[$parameter] = true;
        }

        return array_keys($clean);
    }

    /** Case-insensitive, because a host writing `URL` means `url`. */
    public static function isReserved(string $parameter): bool
    {
        return in_array(strtolower(trim($parameter)), self::RESERVED, true);
    }
}
