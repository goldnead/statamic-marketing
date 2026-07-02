<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Services\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TrackingController extends Controller
{
    public function open(string $uuid, TrackingService $tracking)
    {
        if (config('marketing.tracking.opens', true)) {
            $tracking->recordOpen($uuid);
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
