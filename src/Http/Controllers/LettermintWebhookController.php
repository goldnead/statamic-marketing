<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Services\EspEventProcessor;
use Goldnead\Marketing\Support\LettermintSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lettermint's event webhook: bounces, spam complaints and unsubscribes end up
 * in the same place as every other ESP feedback, {@see EspEventProcessor}.
 *
 * Lettermint cannot send a static token header, so the webhook-manager inbound
 * endpoint does not fit; it signs every delivery instead, and that signature is
 * the only gate. Order matters: nothing is parsed, looked up or cached before
 * the signature has passed, so a forged request has no side effect at all.
 */
class LettermintWebhookController extends Controller
{
    public function __invoke(Request $request, EspEventProcessor $processor): JsonResponse
    {
        $secret = (string) config('marketing.lettermint.webhook_secret', '');

        // Closed until a secret is configured, the same way the webhook-manager
        // endpoint stays disabled without one.
        abort_if($secret === '', 404);

        $valid = LettermintSignature::valid(
            $request->getContent(),
            $request->header('X-Lettermint-Signature'),
            $request->header('X-Lettermint-Delivery'),
            $secret,
            null,
            (int) config('marketing.lettermint.tolerance', LettermintSignature::TOLERANCE),
        );

        if (! $valid) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        // Lettermint retries on anything but a 2xx and sends the same event id
        // each time; a retry must not be counted twice.
        //
        // The mark is taken before processing, so two deliveries racing each
        // other cannot both apply the event, and given back when processing
        // throws. Without the give back, the throw answers 500, Lettermint
        // retries, and the retry is told "Duplicate delivery" with a 200: the
        // unsubscribe or complaint is acknowledged and never applied.
        $id = $payload['id'] ?? null;
        $key = is_string($id) && $id !== '' ? 'marketing:lettermint:event:'.$id : null;

        if ($key !== null && ! Cache::add($key, 1, now()->addDay())) {
            return response()->json(['ok' => true, 'handled' => false, 'message' => 'Duplicate delivery.']);
        }

        try {
            $result = $processor->process($payload, 'lettermint');
        } catch (Throwable $e) {
            if ($key !== null) {
                Cache::forget($key);
            }

            Log::error('Marketing could not process Lettermint event ['.($key !== null ? $id : 'without id').']; the mark was released so the retry is processed.', [
                'event_id' => $key !== null ? $id : null,
                'event' => $payload['event'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        return response()->json($result);
    }
}
