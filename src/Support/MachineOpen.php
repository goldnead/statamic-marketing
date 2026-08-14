<?php

namespace Goldnead\Marketing\Support;

use Goldnead\Marketing\Models\Message;

/**
 * Was this open a person, or a machine loading the image for them?
 *
 * **The honest summary first: this cannot be answered exactly.** Apple's Mail
 * Privacy Protection does not announce itself, and it is designed not to be
 * distinguishable — that is the whole point of it. What follows is a heuristic
 * that catches the common cases and is wrong sometimes, in both directions.
 *
 * Why it matters enough to try. MPP fetches the tracking pixel for *every*
 * message delivered to an Apple Mail address, shortly after delivery, whether or
 * not anybody looks at it — and then caches the image. So for those recipients
 * the first open is not a reading, and there is never a second one when the
 * person actually reads it. Recorded naively, a timeline says "geöffnet" about a
 * mail nobody opened, and stays silent about the one they did.
 *
 * What is trustworthy either way is the **click**. A click is a person. Anything
 * built on opens alone is built on sand, and the reports say so out loud rather
 * than quietly showing a number that flatters.
 *
 * **No IP address and no user agent is stored.** The verdict is computed while
 * the request is in hand and only the boolean is kept. An IP is personal data,
 * a stored user agent is a fingerprint, and neither is needed once the question
 * has been answered.
 */
class MachineOpen
{
    /**
     * Fetchers that are certainly not a person reading.
     *
     * Gmail's `GoogleImageProxy` is deliberately absent: Gmail proxies the image
     * when the message is actually opened, so its fetch *is* a reading. Treating
     * it as machine would throw away real signal from a large share of any list.
     */
    protected const AUTOMATED = [
        'barracuda',
        'mimecast',
        'proofpoint',
        'symantec',
        'microsoft-cryptoapi',
        'skypeuripreview',
        'bitdefender',
        'forcepoint',
        'trendmicro',
        'urlpreview',
        'slackbot',
        'whatsapp',
        'discordbot',
        'facebookexternalhit',
        'curl/',
        'wget/',
        'python-requests',
        'go-http-client',
        'okhttp',
    ];

    /**
     * How long after a send an open with no user agent still reads as a prefetch.
     *
     * Only used as a supporting signal, never on its own with a real user agent:
     * a person who opens a mail within seconds of it arriving is entirely
     * ordinary, and calling that a machine would be worse than saying nothing.
     */
    protected const PREFETCH_SECONDS = 30;

    public static function looksAutomated(?string $userAgent, ?Message $message = null): bool
    {
        $agent = strtolower(trim((string) $userAgent));

        foreach (self::AUTOMATED as $needle) {
            if ($agent !== '' && str_contains($agent, $needle)) {
                return true;
            }
        }

        if (self::isApplePrivacyProxy($agent)) {
            return true;
        }

        // No user agent at all, and the image was fetched within seconds of the
        // send. A human client that hides its agent exists, but one that also
        // manages to open the mail inside half a minute of it being handed to
        // the relay is rare enough to call.
        if ($agent === '' && $message?->sent_at !== null) {
            return $message->sent_at->diffInSeconds(now()) <= self::PREFETCH_SECONDS;
        }

        return false;
    }

    /**
     * Apple's Mail Privacy Protection proxy, as far as it can be recognised.
     *
     * The proxy presents itself as a bare WebKit on a Mac: the `Macintosh`
     * platform and `AppleWebKit`, but none of the `Safari`, `Version` or `Mail`
     * tokens a real client sends. It is a fingerprint, not a declaration, and
     * Apple may change it without notice — a wrong answer here costs a slightly
     * misleading timeline entry, never a wrong send.
     */
    protected static function isApplePrivacyProxy(string $agent): bool
    {
        if ($agent === '' || ! str_contains($agent, 'applewebkit')) {
            return false;
        }

        if (! str_contains($agent, 'macintosh')) {
            return false;
        }

        return ! str_contains($agent, 'safari')
            && ! str_contains($agent, 'version/')
            && ! str_contains($agent, 'mail');
    }
}
