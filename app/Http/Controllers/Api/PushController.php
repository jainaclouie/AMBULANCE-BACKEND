<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function publicKey(): JsonResponse
    {
        $key = config('webpush.vapid.public_key');

        return response()->json(['public_key' => $key ?: null, 'enabled' => (bool) $key]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:500',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'contentEncoding' => 'nullable|string',
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? 'aesgcm',
        );

        return response()->json(['subscribed' => true], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => 'required|url']);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['subscribed' => false]);
    }
}
