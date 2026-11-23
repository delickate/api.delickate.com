<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Fcm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints called by the socket server (trusted infrastructure) using the
 * shared internal secret — not end users. Calls are signalled through the socket
 * server, so it is what tells us to push an "incoming call" notification when a
 * callee has no connected device.
 */
class InternalController extends Controller
{
    public function callNotify(Request $request): JsonResponse
    {
        $secret = (string) config('services.socket.internal_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer', 'min:1'],
            'caller_id' => ['sometimes', 'integer', 'min:1'],
            'caller_name' => ['sometimes', 'string', 'max:120'],
            'conversation_id' => ['sometimes', 'integer', 'min:1'],
            'call_id' => ['sometimes', 'string', 'max:64'],
            'media_type' => ['sometimes', 'in:audio,video'],
            'call_type' => ['sometimes', 'in:direct,group'],
        ]);

        if (! Fcm::enabled()) {
            return response()->json(['sent' => false, 'reason' => 'fcm_disabled']);
        }

        $callerName = $validated['caller_name'] ?? 'Someone';
        $media = ($validated['media_type'] ?? 'audio') === 'video' ? 'video' : 'voice';
        $isGroup = ($validated['call_type'] ?? 'direct') === 'group';

        Fcm::notify(
            $validated['user_ids'],
            $isGroup ? "{$callerName} started a group call" : 'Incoming '.$media.' call',
            $isGroup ? "Tap to join the {$media} call" : "{$callerName} is calling you",
            [
                'type' => 'call',
                'call_type' => $isGroup ? 'group' : 'direct',
                'call_id' => (string) ($validated['call_id'] ?? ''),
                'conversation_id' => (string) ($validated['conversation_id'] ?? ''),
                'caller_id' => (string) ($validated['caller_id'] ?? ''),
                'caller_name' => $callerName,
                'media_type' => $validated['media_type'] ?? 'audio',
            ],
        );

        return response()->json(['sent' => true]);
    }
}
