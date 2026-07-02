<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SubscribeController extends Controller
{
    public function store(
        Request $request,
        MailingListRepository $lists,
        SubscriptionService $subscriptions,
    ) {
        $honeypot = (string) config('marketing.subscriptions.honeypot', 'website');

        // Bots that fill the honeypot get a believable success and nothing else.
        if ($honeypot && $request->filled($honeypot)) {
            return $this->respond($request, Subscription::STATUS_PENDING);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'list' => ['required', 'string'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
        ]);

        $list = $lists->find($data['list']);

        abort_unless($list, 404);

        $subscription = $subscriptions->subscribe(
            $list,
            $data['email'],
            [
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
            ],
            ['source' => 'form'],
        );

        return $this->respond($request, $subscription->status);
    }

    protected function respond(Request $request, string $status)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => ['status' => $status]]);
        }

        if ($redirect = $request->input('_redirect')) {
            return redirect()->to($redirect);
        }

        return back()->with('marketing.subscribed', $status);
    }
}
