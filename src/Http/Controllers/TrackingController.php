<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Services\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TrackingController extends Controller
{
    public function open(Request $request, string $uuid, TrackingService $tracking)
    {
        if (config('marketing.tracking.opens', true)) {
            // The user agent is handed over, used once to decide whether this
            // was a person, and never stored — no agent, no IP. See
            // Support\MachineOpen for what that decision is worth.
            $tracking->recordOpen($uuid, $request->userAgent());
        }

        return response(TrackingService::pixel(), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $uuid, TrackingService $tracking)
    {
        $url = (string) $request->query('url', '');

        abort_unless(str_starts_with($url, 'http://') || str_starts_with($url, 'https://'), 404);

        if (config('marketing.tracking.clicks', true)) {
            $tracking->recordClick($uuid, $url);
        }

        return redirect()->away($url);
    }
}
