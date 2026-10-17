<?php

namespace Goldnead\Marketing\Support;

/**
 * Verifies a Lettermint webhook delivery.
 *
 * Scheme (lettermint.co/docs/platform/webhooks/signing): the header
 * `X-Lettermint-Signature` reads `t={unix seconds},v1={hex}`, where `v1` is
 * HMAC-SHA256 over `{t}.{raw body}` keyed with the webhook secret exactly as
 * shown in the dashboard, `whsec_` prefix included. The official SDKs also
 * require `X-Lettermint-Delivery` to equal `t`, accept any matching `v1` when
 * several are present, and allow 300 seconds of clock difference either way.
 * This does the same and nothing looser.
 */
class LettermintSignature
{
    public const TOLERANCE = 300;

    public static function valid(string $rawBody, ?string $signatureHeader, ?string $deliveryHeader, string $secret, ?int $now = null, int $tolerance = self::TOLERANCE): bool
    {
        if ($secret === '' || $signatureHeader === null || $signatureHeader === '' || $deliveryHeader === null || $deliveryHeader === '') {
            return false;
        }

        $timestamp = null;
        $candidates = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $candidates[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $candidates === []) {
            return false;
        }

        if (trim($deliveryHeader) !== $timestamp) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
